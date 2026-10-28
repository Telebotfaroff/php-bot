<?php

declare(strict_types=1);

return [
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS promo_codes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(32) NOT NULL,
        reward_coins BIGINT UNSIGNED NOT NULL,
        max_uses INT UNSIGNED NOT NULL,
        redeemed_count INT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_by BIGINT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_promo_code (code),
        INDEX idx_promo_active_expiry (is_active, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS promo_code_redemptions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        promo_code_id BIGINT UNSIGNED NOT NULL,
        telegram_user_id BIGINT UNSIGNED NOT NULL,
        coins_awarded BIGINT UNSIGNED NOT NULL,
        redeemed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_promo_user (promo_code_id, telegram_user_id),
        INDEX idx_promo_redemption_user (telegram_user_id, redeemed_at),
        CONSTRAINT fk_promo_redemption_code FOREIGN KEY (promo_code_id)
            REFERENCES promo_codes (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
];
