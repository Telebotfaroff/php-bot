<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\SettingsRepository;
use PDO;
use RuntimeException;
use Throwable;

final class WalletService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsRepository $settings
    ) {}

    /**
     * Issue the configured one-time welcome reward. Safe to call repeatedly.
     * @return int Current wallet balance after the attempt.
     */
    public function grantWelcomeReward(int $userId): int
    {
        $amount = $this->settingAmount('welcome_coins', 0);
        return $this->creditOnce($userId, $amount, 'welcome_reward', 'welcome-bonus-v1');
    }

    public function balance(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT balance FROM wallet_accounts WHERE telegram_user_id = :user');
        $statement->execute(['user' => $userId]);
        $balance = $statement->fetchColumn();
        return $balance === false ? 0 : (int) $balance;
    }

    /**
     * Create a shortener verification task without granting coins.
     * The task cannot be rewarded until a provider adapter validates a server-side event.
     *
     * @return array{id:int, token:string, reward:int, expires_at:string}|null
     */
    public function createVerificationTask(int $userId): ?array
    {
        $provider = trim((string) $this->settings->get('shortener_provider_code', ''));
        $reward = $this->settingAmount('shortener_reward_coins', 0);
        if ($provider === '' || $reward < 1) return null;
        if (!preg_match('/^[a-z][a-z0-9_-]{1,39}$/i', $provider)) {
            throw new RuntimeException('shortener_provider_code has an invalid format.');
        }

        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare(
                "SELECT id, expires_at FROM wallet_verification_tasks
                 WHERE telegram_user_id = :user AND status = 'pending' AND expires_at > CURRENT_TIMESTAMP
                 ORDER BY id DESC LIMIT 1 FOR UPDATE"
            );
            $existing->execute(['user' => $userId]);
            $row = $existing->fetch();
            if (is_array($row)) {
                $this->pdo->commit();
                // The raw token is deliberately not stored; a pending task cannot be reconstructed
                // into a provider URL without issuing a fresh token.
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $insert = $this->pdo->prepare(
                "INSERT INTO wallet_verification_tasks
                 (telegram_user_id, token_hash, provider_code, status, reward_amount, expires_at)
                 VALUES (:user, :hash, :provider, 'pending', :reward, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))"
            );
            $insert->execute([
                'user' => $userId, 'hash' => $hash, 'provider' => $provider, 'reward' => $reward,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $expiry = $this->pdo->query('SELECT DATE_FORMAT(DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR), \'%Y-%m-%d %H:%i:%s\')')->fetchColumn();
            $this->pdo->commit();
            return [
                'id' => $id,
                'token' => $token,
                'reward' => $reward,
                'expires_at' => is_string($expiry) ? $expiry : '',
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Trusted provider adapters call this only after authenticating server-side proof.
     * Repeated callbacks are idempotent and expired tasks cannot credit coins.
     */
    public function creditVerifiedTask(string $token, string $providerCode): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) return false;
        $hash = hash('sha256', strtolower($token));
        $this->pdo->beginTransaction();
        try {
            $taskQuery = $this->pdo->prepare(
                'SELECT * FROM wallet_verification_tasks WHERE token_hash = :hash AND expires_at > CURRENT_TIMESTAMP FOR UPDATE'
            );
            $taskQuery->execute(['hash' => $hash]);
            $task = $taskQuery->fetch();
            if (!is_array($task)
                || !hash_equals((string) $task['provider_code'], $providerCode)
                || $task['status'] !== 'pending') {
                $this->pdo->rollBack();
                return false;
            }

            $userId = (int) $task['telegram_user_id'];
            $amount = (int) $task['reward_amount'];
            $this->ensureWalletRow($userId);
            $wallet = $this->pdo->prepare('SELECT balance FROM wallet_accounts WHERE telegram_user_id = :user FOR UPDATE');
            $wallet->execute(['user' => $userId]);
            $newBalance = (int) $wallet->fetchColumn() + $amount;

            $updateWallet = $this->pdo->prepare('UPDATE wallet_accounts SET balance = :balance WHERE telegram_user_id = :user');
            $updateWallet->execute(['balance' => $newBalance, 'user' => $userId]);

            $ledger = $this->pdo->prepare(
                "INSERT INTO wallet_ledger
                 (telegram_user_id, amount, balance_after, event_type, reference_key)
                 VALUES (:user, :amount, :balance, 'shortener_reward', :reference)"
            );
            $ledger->execute([
                'user' => $userId, 'amount' => $amount, 'balance' => $newBalance,
                'reference' => 'shortener-task-' . (int) $task['id'],
            ]);

            $mark = $this->pdo->prepare(
                "UPDATE wallet_verification_tasks SET status = 'verified', verified_at = CURRENT_TIMESTAMP WHERE id = :id"
            );
            $mark->execute(['id' => (int) $task['id']]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function creditOnce(int $userId, int $amount, string $eventType, string $reference): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->ensureWalletRow($userId);
            $wallet = $this->pdo->prepare('SELECT balance FROM wallet_accounts WHERE telegram_user_id = :user FOR UPDATE');
            $wallet->execute(['user' => $userId]);
            $balance = (int) $wallet->fetchColumn();

            $existing = $this->pdo->prepare(
                'SELECT id FROM wallet_ledger WHERE telegram_user_id = :user AND reference_key = :reference LIMIT 1'
            );
            $existing->execute(['user' => $userId, 'reference' => $reference]);
            if ($existing->fetchColumn() !== false) {
                $this->pdo->commit();
                return $balance;
            }

            if ($amount > 0) {
                $balance += $amount;
                $update = $this->pdo->prepare('UPDATE wallet_accounts SET balance = :balance WHERE telegram_user_id = :user');
                $update->execute(['balance' => $balance, 'user' => $userId]);
                $ledger = $this->pdo->prepare(
                    'INSERT INTO wallet_ledger (telegram_user_id, amount, balance_after, event_type, reference_key)
                     VALUES (:user, :amount, :balance, :event, :reference)'
                );
                $ledger->execute([
                    'user' => $userId, 'amount' => $amount, 'balance' => $balance,
                    'event' => $eventType, 'reference' => $reference,
                ]);
            }
            $this->pdo->commit();
            return $balance;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function ensureWalletRow(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO wallet_accounts (telegram_user_id, balance) VALUES (:user, 0)'
        );
        $statement->execute(['user' => $userId]);
    }

    private function settingAmount(string $key, int $default): int
    {
        $value = filter_var($this->settings->get($key, (string) $default), FILTER_VALIDATE_INT);
        if ($value === false || $value < 0 || $value > 1000000000) {
            throw new RuntimeException($key . ' must be a whole number from 0 to 1000000000.');
        }
        return $value;
    }
}
