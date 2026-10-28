<?php

declare(strict_types=1);

return [
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS wallet_accounts (
        telegram_user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        balance BIGINT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT chk_wallet_nonnegative CHECK (balance >= 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS wallet_ledger (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        telegram_user_id BIGINT UNSIGNED NOT NULL,
        amount BIGINT NOT NULL,
        balance_after BIGINT NOT NULL,
        event_type VARCHAR(40) NOT NULL,
        reference_key VARCHAR(190) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_wallet_reference (telegram_user_id, reference_key),
        INDEX idx_wallet_ledger_user (telegram_user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS premium_subscriptions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        telegram_user_id BIGINT UNSIGNED NOT NULL,
        starts_at DATETIME NOT NULL,
        expires_at DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_premium_user_active (telegram_user_id, status, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS collection_delivery_jobs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        collection_id BIGINT UNSIGNED NOT NULL,
        telegram_user_id BIGINT UNSIGNED NOT NULL,
        request_update_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'pending',
        access_type VARCHAR(20) NOT NULL,
        coin_cost BIGINT UNSIGNED NOT NULL DEFAULT 0,
        total_files INT UNSIGNED NOT NULL DEFAULT 0,
        delivered_files INT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_delivery_update (request_update_id),
        INDEX idx_delivery_resume (telegram_user_id, collection_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS collection_delivery_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        job_id BIGINT UNSIGNED NOT NULL,
        collection_file_id BIGINT UNSIGNED NOT NULL,
        sequence_no INT UNSIGNED NOT NULL,
        batch_no INT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        delivered_message_id BIGINT NULL,
        delivered_chat_id BIGINT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(500) NULL,
        delivered_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_delivery_file (job_id, collection_file_id),
        UNIQUE KEY uq_delivery_sequence (job_id, sequence_no),
        INDEX idx_delivery_item_status (job_id, status),
        CONSTRAINT fk_delivery_item_job FOREIGN KEY (job_id)
            REFERENCES collection_delivery_jobs (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS delivery_outgoing_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        job_id BIGINT UNSIGNED NOT NULL,
        telegram_user_id BIGINT UNSIGNED NOT NULL,
        chat_id BIGINT NOT NULL,
        message_id BIGINT NOT NULL,
        category VARCHAR(40) NOT NULL DEFAULT 'collection_video',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_delivery_outgoing (chat_id, message_id),
        INDEX idx_delivery_outgoing_user (telegram_user_id, created_at),
        CONSTRAINT fk_outgoing_delivery_job FOREIGN KEY (job_id)
            REFERENCES collection_delivery_jobs (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
];
