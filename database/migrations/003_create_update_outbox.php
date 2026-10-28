<?php

declare(strict_types=1);

return [
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS telegram_update_jobs (
        update_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        payload_json LONGTEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        locked_until TIMESTAMP NULL,
        last_error VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_update_jobs_ready (status, available_at),
        INDEX idx_update_jobs_lock (status, locked_until),
        CONSTRAINT fk_update_jobs_update
            FOREIGN KEY (update_id) REFERENCES telegram_updates (update_id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
];
