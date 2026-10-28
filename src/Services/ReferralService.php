<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

final class ReferralService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Mark a user's first /start and optionally qualify a referral.
     * The referrer receives 24 hours of Premium once per genuinely new bot user.
     *
     * @return array{is_new:bool,rewarded:bool,referrer_id:?int}
     */
    public function processStart(int $userId, ?int $referrerId): array
    {
        $this->pdo->beginTransaction();
        try {
            $user = $this->pdo->prepare('SELECT bot_started_at FROM users WHERE telegram_user_id = :id FOR UPDATE');
            $user->execute(['id' => $userId]);
            $startedAt = $user->fetchColumn();
            if ($startedAt === false) {
                $this->pdo->commit();
                return ['is_new' => false, 'rewarded' => false, 'referrer_id' => null];
            }
            if ($startedAt !== null) {
                $this->pdo->commit();
                return ['is_new' => false, 'rewarded' => false, 'referrer_id' => null];
            }

            $mark = $this->pdo->prepare('UPDATE users SET bot_started_at = CURRENT_TIMESTAMP WHERE telegram_user_id = :id AND bot_started_at IS NULL');
            $mark->execute(['id' => $userId]);
            $validReferrer = $referrerId !== null && $referrerId > 0 && $referrerId !== $userId;
            if (!$validReferrer) {
                $this->pdo->commit();
                return ['is_new' => true, 'rewarded' => false, 'referrer_id' => null];
            }

            $referrer = $this->pdo->prepare('SELECT telegram_user_id FROM users WHERE telegram_user_id = :id');
            $referrer->execute(['id' => $referrerId]);
            if ($referrer->fetchColumn() === false) {
                $this->pdo->commit();
                return ['is_new' => true, 'rewarded' => false, 'referrer_id' => null];
            }

            $attribution = $this->pdo->prepare(
                "INSERT IGNORE INTO referral_attributions (referrer_user_id, referred_user_id, status)
                 VALUES (:referrer, :referred, 'qualified')"
            );
            $attribution->execute(['referrer' => $referrerId, 'referred' => $userId]);
            if ($attribution->rowCount() !== 1) {
                $this->pdo->commit();
                return ['is_new' => true, 'rewarded' => false, 'referrer_id' => null];
            }

            $active = $this->pdo->prepare(
                "SELECT id, expires_at FROM premium_subscriptions
                 WHERE telegram_user_id = :user AND status = 'active'
                   AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
                 ORDER BY (expires_at IS NULL) DESC, expires_at DESC LIMIT 1 FOR UPDATE"
            );
            $active->execute(['user' => $referrerId]);
            $current = $active->fetch();
            if (is_array($current) && $current['expires_at'] === null) {
                $subscriptionId = (int) $current['id'];
            } elseif (is_array($current)) {
                $extend = $this->pdo->prepare(
                    'UPDATE premium_subscriptions SET expires_at = DATE_ADD(GREATEST(expires_at, CURRENT_TIMESTAMP), INTERVAL 1 DAY) WHERE id = :id'
                );
                $extend->execute(['id' => (int) $current['id']]);
                $subscriptionId = (int) $current['id'];
            } else {
                $insert = $this->pdo->prepare(
                    "INSERT INTO premium_subscriptions (telegram_user_id, starts_at, expires_at, status)
                     VALUES (:user, CURRENT_TIMESTAMP, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 DAY), 'active')"
                );
                $insert->execute(['user' => $referrerId]);
                $subscriptionId = (int) $this->pdo->lastInsertId();
            }

            $reward = $this->pdo->prepare(
                "INSERT INTO referral_rewards (referrer_user_id, referred_user_id, reward_type, subscription_id)
                 VALUES (:referrer, :referred, 'premium_24h', :subscription)"
            );
            $reward->execute(['referrer' => $referrerId, 'referred' => $userId, 'subscription' => $subscriptionId]);
            $this->pdo->commit();
            return ['is_new' => true, 'rewarded' => true, 'referrer_id' => $referrerId];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array{qualified:int,rewards:int} */
    public function stats(int $referrerId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS qualified,
                    (SELECT COUNT(*) FROM referral_rewards WHERE referrer_user_id = :user2) AS rewards
             FROM referral_attributions WHERE referrer_user_id = :user AND status = 'qualified'"
        );
        $stmt->execute(['user' => $referrerId, 'user2' => $referrerId]);
        $row = $stmt->fetch();
        return ['qualified' => (int) ($row['qualified'] ?? 0), 'rewards' => (int) ($row['rewards'] ?? 0)];
    }
}
