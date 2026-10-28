<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use JsonException;

final class DatabaseBackupService
{
    private const MAX_BYTES = 8_000_000;

    public function __construct(private readonly PDO $pdo) {}

    /** Create a secret-redacted logical backup in a private temporary file. */
    public function createTemporaryBackup(): string
    {
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($tables) || $tables === []) {
            throw new RuntimeException('No database tables were found.');
        }

        sort($tables, SORT_STRING);
        $payload = [
            'format' => 'php-bot-logical-backup',
            'format_version' => 1,
            'created_at_utc' => gmdate('c'),
            'schema_migrations' => [],
            'tables' => [],
            'redaction' => 'Sensitive bot_settings entries are omitted.',
        ];

        foreach ($tables as $tableName) {
            $table = (string) $tableName;
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                throw new RuntimeException('Unexpected database table name.');
            }
            if ($table === 'schema_migrations') {
                $payload['schema_migrations'] = $this->pdo
                    ->query('SELECT migration, applied_at FROM schema_migrations ORDER BY migration')
                    ->fetchAll(PDO::FETCH_ASSOC);
                continue;
            }

            $statement = $this->pdo->query('SELECT * FROM `' . $table . '`');
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if ($table === 'bot_settings') {
                $rows = array_values(array_filter($rows, static function (array $row): bool {
                    $key = strtolower((string) ($row['setting_key'] ?? ''));
                    return !preg_match('/(?:token|secret|password|passwd|api[_-]?key|private[_-]?key|credential|webhook)/i', $key);
                }));
            }
            $payload['tables'][$table] = [
                'row_count' => count($rows),
                'rows' => $rows,
            ];
        }

        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Database contains data that cannot be exported as JSON.', 0, $exception);
        }

        if (strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException('Backup exceeds the 8 MB safe delivery limit. Use your database provider export for a full backup.');
        }

        $path = tempnam(sys_get_temp_dir(), 'php-bot-backup-');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary backup file.');
        }
        @chmod($path, 0600);
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            @unlink($path);
            throw new RuntimeException('Could not write the temporary backup file.');
        }
        return $path;
    }
}
