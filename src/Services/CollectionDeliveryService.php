<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\SettingsRepository;
use App\Telegram\BotApiClient;
use PDO;
use RuntimeException;
use Throwable;

final class CollectionDeliveryService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BotApiClient $telegram,
        private readonly SettingsRepository $settings
    ) {}

    public function open(int $chatId, int $userId, int $updateId, string $payload): bool
    {
        if (!preg_match('/^post_([a-f0-9]{32})$/i', $payload, $match)) {
            $this->telegram->sendMessage($chatId, 'That collection link is invalid. Please open the link from the original post.');
            return true;
        }

        $statement = $this->pdo->prepare(
            "SELECT id, title, public_code, thumbnail_file_id FROM collections
             WHERE public_code = :code AND status = 'published' LIMIT 1"
        );
        $statement->execute(['code' => strtolower($match[1])]);
        $collection = $statement->fetch();
        if (!is_array($collection)) {
            $this->telegram->sendMessage($chatId, 'This collection is unavailable or the link has expired.');
            return true;
        }

        if (!$this->passesSubscription($chatId, $userId)) {
            return true;
        }

        $collectionId = (int) $collection['id'];
        $job = $this->findResumableJob($userId, $collectionId);
        if ($job !== null) {
            $jobId = (int) $job['id'];
        } else {
            $access = $this->authorizeAndCreateJob($collectionId, $userId, $updateId);
            if ($access === null) {
                $this->showInsufficientBalance($chatId, $userId);
                return true;
            }
            $jobId = $access;
        }

        $this->deliverJob($jobId, $chatId, $userId, (string) $collection['title']);
        return true;
    }

    private function passesSubscription(int $chatId, int $userId): bool
    {
        $channel = trim((string) $this->settings->get('force_subscribe_channel_id', ''));
        if ($channel === '') return true;

        try {
            $response = $this->telegram->call('getChatMember', [
                'chat_id' => $channel,
                'user_id' => $userId,
            ]);
            $member = $response['result'] ?? null;
            $status = is_array($member) ? (string) ($member['status'] ?? '') : '';
            $allowed = in_array($status, ['creator', 'administrator', 'member'], true)
                || ($status === 'restricted' && (bool) ($member['is_member'] ?? false));
            if ($allowed) return true;
        } catch (Throwable) {
            // Fail closed: an API failure must not bypass the configured membership gate.
            $this->telegram->sendMessage($chatId, 'I could not verify your channel membership right now. Please try again shortly.');
            return false;
        }

        $keyboard = [];
        if (str_starts_with($channel, '@')) {
            $keyboard[] = [['text' => 'Join required channel', 'url' => 'https://t.me/' . ltrim($channel, '@')]];
        }
        $keyboard[] = [['text' => 'I have joined — Check again', 'callback_data' => 'collection:check_sub']];
        $this->telegram->sendMessage($chatId, '🔒 Please join the required channel first, then tap “I have joined — Check again”.', [
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ]);
        return false;
    }

    private function authorizeAndCreateJob(int $collectionId, int $userId, int $updateId): ?int
    {
        $cost = filter_var($this->settings->get('collection_access_cost', '0'), FILTER_VALIDATE_INT);
        if ($cost === false || $cost < 0) {
            throw new RuntimeException('collection_access_cost must be a non-negative integer.');
        }

        $this->pdo->beginTransaction();
        try {
            $premium = $this->pdo->prepare(
                "SELECT id FROM premium_subscriptions
                 WHERE telegram_user_id = :user AND status = 'active'
                   AND starts_at <= CURRENT_TIMESTAMP
                   AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
                 ORDER BY expires_at IS NULL DESC, expires_at DESC LIMIT 1 FOR UPDATE"
            );
            $premium->execute(['user' => $userId]);
            $isPremium = $premium->fetchColumn() !== false;
            $accessType = $isPremium ? 'premium' : ($cost === 0 ? 'free' : 'coins');
            $charge = $isPremium ? 0 : $cost;

            if ($charge > 0) {
                $wallet = $this->pdo->prepare('SELECT balance FROM wallet_accounts WHERE telegram_user_id = :user FOR UPDATE');
                $wallet->execute(['user' => $userId]);
                $balance = $wallet->fetchColumn();
                if ($balance === false || (int) $balance < $charge) {
                    $this->pdo->rollBack();
                    return null;
                }
                $newBalance = (int) $balance - $charge;
                $updateWallet = $this->pdo->prepare(
                    'UPDATE wallet_accounts SET balance = :balance WHERE telegram_user_id = :user'
                );
                $updateWallet->execute(['balance' => $newBalance, 'user' => $userId]);
            } else {
                $newBalance = null;
            }

            $files = $this->pdo->prepare('SELECT COUNT(*) FROM collection_files WHERE collection_id = :collection');
            $files->execute(['collection' => $collectionId]);
            $fileCount = (int) $files->fetchColumn();
            if ($fileCount < 1) {
                throw new RuntimeException('Published collection contains no files.');
            }

            $insert = $this->pdo->prepare(
                "INSERT INTO collection_delivery_jobs
                 (collection_id, telegram_user_id, request_update_id, status, access_type, coin_cost, total_files)
                 VALUES (:collection, :user, :update_id, 'processing', :access_type, :cost, :total)"
            );
            $insert->execute([
                'collection' => $collectionId, 'user' => $userId, 'update_id' => $updateId,
                'access_type' => $accessType, 'cost' => $charge, 'total' => $fileCount,
            ]);
            $jobId = (int) $this->pdo->lastInsertId();

            $items = $this->pdo->prepare(
                'INSERT INTO collection_delivery_items
                 (job_id, collection_file_id, sequence_no, batch_no, status)
                 SELECT :job, id, sequence_no, CEIL(sequence_no / 10), \'pending\'
                 FROM collection_files WHERE collection_id = :collection ORDER BY sequence_no'
            );
            $items->execute(['job' => $jobId, 'collection' => $collectionId]);

            if ($charge > 0) {
                $ledger = $this->pdo->prepare(
                    "INSERT INTO wallet_ledger
                     (telegram_user_id, amount, balance_after, event_type, reference_key)
                     VALUES (:user, :amount, :balance, 'collection_access', :reference)"
                );
                $ledger->execute([
                    'user' => $userId, 'amount' => -$charge, 'balance' => $newBalance,
                    'reference' => 'collection-access-job-' . $jobId,
                ]);
            }

            $this->pdo->commit();
            return $jobId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function findResumableJob(int $userId, int $collectionId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM collection_delivery_jobs
             WHERE telegram_user_id = :user AND collection_id = :collection
               AND status IN ('pending', 'processing', 'failed')
             ORDER BY id DESC LIMIT 1"
        );
        $statement->execute(['user' => $userId, 'collection' => $collectionId]);
        $row = $statement->fetch();
        if (!is_array($row)) return null;

        $update = $this->pdo->prepare(
            "UPDATE collection_delivery_jobs SET status = 'processing', last_error = NULL WHERE id = :id"
        );
        $update->execute(['id' => (int) $row['id']]);
        return $row;
    }

    private function deliverJob(int $jobId, int $chatId, int $userId, string $title): void
    {
        $statement = $this->pdo->prepare(
            "SELECT i.id AS item_id, i.sequence_no, i.status AS item_status,
                    f.dump_chat_id, f.dump_message_id
             FROM collection_delivery_items i
             INNER JOIN collection_files f ON f.id = i.collection_file_id
             WHERE i.job_id = :job ORDER BY i.sequence_no ASC"
        );
        $statement->execute(['job' => $jobId]);
        $items = $statement->fetchAll();
        $delivered = 0;

        try {
            foreach ($items as $item) {
                if ($item['item_status'] === 'delivered') {
                    $delivered++;
                    continue;
                }

                $attempt = $this->pdo->prepare(
                    'UPDATE collection_delivery_items SET attempts = attempts + 1 WHERE id = :id'
                );
                $attempt->execute(['id' => (int) $item['item_id']]);

                try {
                    $result = $this->telegram->call('copyMessage', [
                        'chat_id' => $chatId,
                        'from_chat_id' => $item['dump_chat_id'],
                        'message_id' => (int) $item['dump_message_id'],
                    ]);
                    $messageId = filter_var($result['result']['message_id'] ?? null, FILTER_VALIDATE_INT);
                    if ($messageId === false || $messageId === null) {
                        throw new RuntimeException('Telegram did not return a delivered message ID.');
                    }

                    $this->pdo->beginTransaction();
                    $mark = $this->pdo->prepare(
                        "UPDATE collection_delivery_items SET status = 'delivered', delivered_message_id = :message,
                         delivered_chat_id = :chat, delivered_at = CURRENT_TIMESTAMP, last_error = NULL WHERE id = :id"
                    );
                    $mark->execute(['message' => $messageId, 'chat' => $chatId, 'id' => (int) $item['item_id']]);
                    $outgoing = $this->pdo->prepare(
                        "INSERT IGNORE INTO delivery_outgoing_messages
                         (job_id, telegram_user_id, chat_id, message_id, category)
                         VALUES (:job, :user, :chat, :message, 'collection_video')"
                    );
                    $outgoing->execute([
                        'job' => $jobId, 'user' => $userId, 'chat' => $chatId, 'message' => $messageId,
                    ]);
                    $progress = $this->pdo->prepare(
                        "UPDATE collection_delivery_jobs SET delivered_files = delivered_files + 1 WHERE id = :job"
                    );
                    $progress->execute(['job' => $jobId]);
                    $this->pdo->commit();
                    $delivered++;
                } catch (Throwable $exception) {
                    if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                    $markFailed = $this->pdo->prepare(
                        "UPDATE collection_delivery_items SET status = 'failed', last_error = :error WHERE id = :id"
                    );
                    $markFailed->execute([
                        'error' => substr($exception->getMessage(), 0, 500), 'id' => (int) $item['item_id'],
                    ]);
                    throw $exception;
                }
            }

            $finish = $this->pdo->prepare(
                "UPDATE collection_delivery_jobs SET status = 'completed', delivered_files = :count, last_error = NULL WHERE id = :id"
            );
            $finish->execute(['count' => $delivered, 'id' => $jobId]);
            $this->telegram->sendMessage($chatId, '✅ Collection delivery complete: ' . $title . "\nVideos delivered: " . $delivered);
        } catch (Throwable $exception) {
            $fail = $this->pdo->prepare(
                "UPDATE collection_delivery_jobs SET status = 'failed', delivered_files = :count, last_error = :error WHERE id = :id"
            );
            $fail->execute([
                'count' => $delivered, 'error' => substr($exception->getMessage(), 0, 500), 'id' => $jobId,
            ]);
            $this->telegram->sendMessage($chatId,
                '⚠️ Delivery paused after ' . $delivered . ' video(s). Your progress is saved. Open the same collection link again to retry remaining videos.');
        }
    }

    private function showInsufficientBalance(int $chatId, int $userId): void
    {
        $cost = max(0, (int) $this->settings->get('collection_access_cost', '0'));
        $statement = $this->pdo->prepare('SELECT balance FROM wallet_accounts WHERE telegram_user_id = :user');
        $statement->execute(['user' => $userId]);
        $balance = $statement->fetchColumn();
        $current = $balance === false ? 0 : (int) $balance;
        $this->telegram->sendMessage($chatId,
            "You need {$cost} coins to open this collection, but your balance is {$current}. No coins were deducted.\n\nUse Get Coins or view Premium plans to continue.",
            ['reply_markup' => ['inline_keyboard' => [
                [['text' => 'Get Coins', 'callback_data' => 'wallet:get_coins']],
                [['text' => 'View Premium Plans', 'callback_data' => 'premium:plans']],
            ]]]
        );
    }
}
