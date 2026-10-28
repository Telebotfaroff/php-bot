<?php

declare(strict_types=1);

return [
    <<<'SQL'
    ALTER TABLE users
        ADD COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN banned_at DATETIME NULL,
        ADD COLUMN banned_by BIGINT NULL,
        ADD COLUMN ban_reason VARCHAR(255) NULL,
        ADD INDEX idx_users_banned_last_seen (is_banned, last_seen_at)
    SQL,
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS bot_search_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        telegram_user_id BIGINT UNSIGNED NOT NULL,
        query_hash CHAR(64) NOT NULL,
        result_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_search_created (created_at),
        INDEX idx_search_user_created (telegram_user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
];
