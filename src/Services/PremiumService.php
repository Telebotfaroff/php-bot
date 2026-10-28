<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class PremiumService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array<string, mixed>> */
    public function plans(bool $enabledOnly = true): array
    {
        $sql = 'SELECT id, name, duration_days, price_amount, currency, enabled FROM premium_plans'
            . ($enabledOnly ? ' WHERE enabled = 1' : '') . ' ORDER BY price_amount, id';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function createPlan(string $name, int $days, string $price, string $currency): int
    {
        $name = trim($name);
        $currency = strtoupper(trim($currency));
        if ($name === '' || strlen($name) > 100 || $days < 1 || $days > 36500
            || !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/', $price)
            || !preg_match('/^[A-Z]{3,8}$/', $currency)) {
            throw new RuntimeException('Invalid plan. Use a name, duration days, price and currency.');
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO premium_plans (name, duration_days, price_amount, currency) VALUES (:name, :days, :price, :currency)'
        );
        $insert->execute(['name' => $name, 'days' => $days, 'price' => $price, 'currency' => $currency]);
        return (int) $this->pdo->lastInsertId();
    }

    public function disablePlan(int $planId): bool
    {
        $statement = $this->pdo->prepare('UPDATE premium_plans SET enabled = 0 WHERE id = :id');
        $statement->execute(['id' => $planId]);
        return $statement->rowCount() > 0;
    }

    public function createRequest(int $userId, int $planId, string $method): int
    {
        $method = strtolower($method);
        if (!in_array($method, ['upi', 'crypto'], true)) {
            throw new RuntimeException('Payment method must be UPI or crypto.');
        }
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT * FROM premium_plans WHERE id = :id AND enabled = 1 FOR UPDATE');
            $query->execute(['id' => $planId]);
            $plan = $query->fetch();
            if (!is_array($plan)) throw new RuntimeException('That Premium plan is no longer available.');

            $existing = $this->pdo->prepare(
                "SELECT id FROM premium_payment_requests WHERE telegram_user_id = :user AND status = 'pending' LIMIT 1 FOR UPDATE"
            );
            $existing->execute(['user' => $userId]);
            if ($existing->fetchColumn() !== false) {
                throw new RuntimeException('You already have a pending Premium payment request. Wait for admin review.');
            }

            $insert = $this->pdo->prepare(
                "INSERT INTO premium_payment_requests
                 (telegram_user_id, plan_id, plan_name_snapshot, duration_days_snapshot, price_amount_snapshot,
                  currency_snapshot, payment_method, status)
                 VALUES (:user, :plan, :name, :days, :price, :currency, :method, 'pending')"
            );
            $insert->execute([
                'user' => $userId, 'plan' => $planId, 'name' => $plan['name'],
                'days' => $plan['duration_days'], 'price' => $plan['price_amount'],
                'currency' => $plan['currency'], 'method' => $method,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function submitReference(int $userId, int $requestId, string $reference): bool
    {
        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 190 || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
            throw new RuntimeException('Reference must be 1–190 printable characters.');
        }
        $statement = $this->pdo->prepare(
            "UPDATE premium_payment_requests SET transaction_reference = :reference
             WHERE id = :id AND telegram_user_id = :user AND status = 'pending'"
        );
        $statement->execute(['reference' => $reference, 'id' => $requestId, 'user' => $userId]);
        return $statement->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> */
    public function pendingRequests(): array
    {
        return $this->pdo->query(
            "SELECT id, telegram_user_id, plan_name_snapshot, duration_days_snapshot, price_amount_snapshot,
                    currency_snapshot, payment_method, transaction_reference, created_at
             FROM premium_payment_requests WHERE status = 'pending' ORDER BY created_at, id LIMIT 50"
        )->fetchAll();
    }

    public function decide(int $requestId, int $adminId, bool $approve, string $note = ''): bool
    {
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT * FROM premium_payment_requests WHERE id = :id FOR UPDATE');
            $query->execute(['id' => $requestId]);
            $request = $query->fetch();
            if (!is_array($request) || $request['status'] !== 'pending') {
                $this->pdo->rollBack();
                return false;
            }
            if ($approve && trim((string) ($request['transaction_reference'] ?? '')) === '') {
                throw new RuntimeException('User has not submitted a transaction reference yet.');
            }

            $subscriptionId = null;
            if ($approve) {
                $userId = (int) $request['telegram_user_id'];
                $days = (int) $request['duration_days_snapshot'];
                $active = $this->pdo->prepare(
                    "SELECT id, expires_at FROM premium_subscriptions
                     WHERE telegram_user_id = :user AND status = 'active'
                       AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
                     ORDER BY (expires_at IS NULL) DESC, expires_at DESC LIMIT 1 FOR UPDATE"
                );
                $active->execute(['user' => $userId]);
                $current = $active->fetch();
                if (is_array($current) && $current['expires_at'] === null) {
                    // A lifetime subscription is already active; approval records the request without shortening it.
                    $subscriptionId = (int) $current['id'];
                } elseif (is_array($current)) {
                    $update = $this->pdo->prepare(
                        'UPDATE premium_subscriptions SET expires_at = DATE_ADD(GREATEST(expires_at, CURRENT_TIMESTAMP), INTERVAL :days DAY) WHERE id = :id'
                    );
                    $update->execute(['days' => $days, 'id' => (int) $current['id']]);
                    $subscriptionId = (int) $current['id'];
                } else {
                    $insert = $this->pdo->prepare(
                        "INSERT INTO premium_subscriptions (telegram_user_id, starts_at, expires_at, status)
                         VALUES (:user, CURRENT_TIMESTAMP, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :days DAY), 'active')"
                    );
                    $insert->execute(['user' => $userId, 'days' => $days]);
                    $subscriptionId = (int) $this->pdo->lastInsertId();
                }
            }

            $updateRequest = $this->pdo->prepare(
                'UPDATE premium_payment_requests SET status = :status, admin_note = :note, reviewed_by = :admin,
                 reviewed_at = CURRENT_TIMESTAMP, subscription_id = :subscription WHERE id = :id'
            );
            $updateRequest->execute([
                'status' => $approve ? 'approved' : 'rejected',
                'note' => $note === '' ? null : substr($note, 0, 500),
                'admin' => $adminId, 'subscription' => $subscriptionId, 'id' => $requestId,
            ]);
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
