<?php

declare(strict_types=1);

return [
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS wallet_verification_tasks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        telegram_user_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        provider_code VARCHAR(40) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        reward_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        verified_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_wallet_task_token_hash (token_hash),
        INDEX idx_wallet_task_user_status (telegram_user_id, status, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
];
