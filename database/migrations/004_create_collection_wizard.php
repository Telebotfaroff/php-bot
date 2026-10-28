<?php

declare(strict_types=1);

return [
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS collection_drafts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        owner_user_id BIGINT UNSIGNED NOT NULL,
        chat_id BIGINT NOT NULL,
        state VARCHAR(24) NOT NULL DEFAULT 'thumbnail',
        title VARCHAR(255) NULL,
        thumbnail_file_id VARCHAR(255) NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'collecting',
        preview_message_id BIGINT NULL,
        published_collection_id BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_drafts_owner_status (owner_user_id, status),
        INDEX idx_drafts_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS collection_draft_files (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        draft_id BIGINT UNSIGNED NOT NULL,
        sequence_no INT UNSIGNED NOT NULL,
        source_file_id VARCHAR(255) NOT NULL,
        dump_chat_id VARCHAR(255) NOT NULL,
        dump_message_id BIGINT NOT NULL,
        media_kind VARCHAR(20) NOT NULL DEFAULT 'video',
        file_name VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_draft_sequence (draft_id, sequence_no),
        UNIQUE KEY uq_draft_dump_message (dump_chat_id, dump_message_id),
        CONSTRAINT fk_draft_files_draft FOREIGN KEY (draft_id)
            REFERENCES collection_drafts (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS collections (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        public_code CHAR(32) NOT NULL,
        thumbnail_file_id VARCHAR(255) NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'publishing',
        post_chat_id VARCHAR(255) NULL,
        post_message_id BIGINT NULL,
        created_by BIGINT UNSIGNED NOT NULL,
        published_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_collections_public_code (public_code),
        INDEX idx_collections_status_created (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
    <<<'SQL'
    CREATE TABLE IF NOT EXISTS collection_files (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        collection_id BIGINT UNSIGNED NOT NULL,
        sequence_no INT UNSIGNED NOT NULL,
        source_file_id VARCHAR(255) NOT NULL,
        dump_chat_id VARCHAR(255) NOT NULL,
        dump_message_id BIGINT NOT NULL,
        media_kind VARCHAR(20) NOT NULL DEFAULT 'video',
        file_name VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_collection_sequence (collection_id, sequence_no),
        UNIQUE KEY uq_collection_dump_message (dump_chat_id, dump_message_id),
        CONSTRAINT fk_collection_files_collection FOREIGN KEY (collection_id)
            REFERENCES collections (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    SQL,
];
