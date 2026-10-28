<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationDirectory
    ) {
    }

    /** @return list<string> Names of migrations applied during this run. */
    public function migrate(): array
    {
        if (!is_dir($this->migrationDirectory)) {
            throw new RuntimeException('Migration directory does not exist.');
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $files = glob(rtrim($this->migrationDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            throw new RuntimeException('Unable to list database migrations.');
        }
        sort($files, SORT_STRING);

        $applied = [];
        $check = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration = :migration');
        $record = $this->pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');

        foreach ($files as $file) {
            $name = basename($file);
            $statements = require $file;
            if (!is_array($statements)) {
                throw new RuntimeException('Migration must return an array of SQL statements: ' . $name);
            }

            $check->execute(['migration' => $name]);
            if ($check->fetchColumn() !== false) {
                continue;
            }

            // MySQL DDL may auto-commit. Migrations should therefore be
            // additive and safe to inspect/re-run after a partial failure.
            foreach ($statements as $statement) {
                if (!is_string($statement) || trim($statement) === '') {
                    throw new RuntimeException('Invalid SQL statement in migration: ' . $name);
                }
                $this->pdo->exec($statement);
            }

            $record->execute(['migration' => $name]);
            $applied[] = $name;
        }

        return $applied;
    }
}
