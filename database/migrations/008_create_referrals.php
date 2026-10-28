<?php

declare(strict_types=1);

return [
    <<<'SQL'
    ALTER TABLE users ADD COLUMN bot_started_at DATETIME NULL
    SQL,
    <<<'SQL'
    UPDATE users SET bot_started_at = created_at WHERE bot_started_at IS NULL
    SQL,
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS referral_attributions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        referrer_user_id BIGINT UNSIGNED NOT NULL,
        referred_user_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'qualified',
        qualified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_referral_referred (referred_user_id),
        INDEX idx_referral_referrer (referrer_user_id, status, qualified_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS referral_rewards (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        referrer_user_id BIGINT UNSIGNED NOT NULL,
        referred_user_id BIGINT UNSIGNED NOT NULL,
        reward_type VARCHAR(40) NOT NULL DEFAULT 'premium_24h',
        subscription_id BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_referral_reward_referred (referred_user_id),
        INDEX idx_referral_reward_referrer (referrer_user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
];
