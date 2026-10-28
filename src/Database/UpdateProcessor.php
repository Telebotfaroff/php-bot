<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use Throwable;

final class UpdateProcessor
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Persist a Telegram update and its sender atomically.
     * Returns false when Telegram retries an update already stored.
     *
     * @param array<string, mixed> $update
     */
    public function process(array $update): bool
    {
        $updateId = filter_var($update['update_id'] ?? null, FILTER_VALIDATE_INT);
        if ($updateId === false || $updateId === null || $updateId < 0) {
            throw new \InvalidArgumentException('Telegram update_id must be a non-negative integer.');
        }

        $type = self::updateType($update);
        $this->pdo->beginTransaction();

        try {
            $insert = $this->pdo->prepare(
                "INSERT IGNORE INTO telegram_updates (update_id, update_type, status, processed_at)
                 VALUES (:update_id, :update_type, 'processed', CURRENT_TIMESTAMP)"
            );
            $insert->execute([
                'update_id' => $updateId,
                'update_type' => $type,
            ]);

            if ($insert->rowCount() === 0) {
                $this->pdo->commit();
                return false;
            }

            $sender = self::sender($update);
            if ($sender !== null) {
                $user = $this->pdo->prepare(
                    'INSERT INTO users
                        (telegram_user_id, username, first_name, last_name, language_code, is_bot, last_seen_at)
                     VALUES
                        (:id, :username, :first_name, :last_name, :language_code, :is_bot, CURRENT_TIMESTAMP)
                     ON DUPLICATE KEY UPDATE
                        username = VALUES(username),
                        first_name = VALUES(first_name),
                        last_name = VALUES(last_name),
                        language_code = VALUES(language_code),
                        is_bot = VALUES(is_bot),
                        last_seen_at = CURRENT_TIMESTAMP'
                );
                $user->execute([
                    'id' => $sender['id'],
                    'username' => $sender['username'],
                    'first_name' => $sender['first_name'],
                    'last_name' => $sender['last_name'],
                    'language_code' => $sender['language_code'],
                    'is_bot' => $sender['is_bot'] ? 1 : 0,
                ]);
            }

            $this->pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $update */
    private static function updateType(array $update): string
    {
        foreach ([
            'message', 'edited_message', 'channel_post', 'edited_channel_post',
            'callback_query', 'inline_query', 'chosen_inline_result',
            'my_chat_member', 'chat_member', 'chat_join_request', 'pre_checkout_query',
            'shipping_query', 'poll', 'poll_answer', 'business_message',
        ] as $key) {
            if (isset($update[$key])) {
                return $key;
            }
        }
        return 'other';
    }

    /**
     * @param array<string, mixed> $update
     * @return array{id:int, username:?string, first_name:?string, last_name:?string, language_code:?string, is_bot:bool}|null
     */
    private static function sender(array $update): ?array
    {
        $messageKeys = [
            'message', 'edited_message', 'channel_post', 'edited_channel_post',
            'business_message',
        ];

        foreach ($messageKeys as $key) {
            if (isset($update[$key]['from']) && is_array($update[$key]['from'])) {
                return self::normalizeUser($update[$key]['from']);
            }
        }

        foreach (['callback_query', 'inline_query', 'chosen_inline_result', 'my_chat_member', 'chat_member', 'chat_join_request', 'pre_checkout_query', 'shipping_query', 'poll_answer'] as $key) {
            if (isset($update[$key]['from']) && is_array($update[$key]['from'])) {
                return self::normalizeUser($update[$key]['from']);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $user */
    private static function normalizeUser(array $user): ?array
    {
        $id = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id === null || $id < 1) {
            return null;
        }

        return [
            'id' => $id,
            'username' => self::nullableString($user['username'] ?? null),
            'first_name' => self::nullableString($user['first_name'] ?? null),
            'last_name' => self::nullableString($user['last_name'] ?? null),
            'language_code' => self::nullableString($user['language_code'] ?? null),
            'is_bot' => (bool) ($user['is_bot'] ?? false),
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 255) : null;
    }
}
