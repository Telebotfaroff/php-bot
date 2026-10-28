<?php

declare(strict_types=1);

return [
    <<<'SQL'
    ALTER TABLE delivery_outgoing_messages
        ADD COLUMN auto_delete_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        ADD COLUMN auto_delete_last_error VARCHAR(500) NULL
    SQL,
];
