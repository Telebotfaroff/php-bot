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


    /**
     * Validate a backup without changing the database. Returns a safe dry-run summary.
     *
     * @return array{format_version:int,created_at_utc:string,table_count:int,row_count:int,omitted_tables:list<string>}
     */
    public function validateBackupFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Backup file is unavailable.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > self::MAX_BYTES) {
            throw new RuntimeException('Backup file size is outside the allowed range.');
        }
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException('Could not read backup file.');
        }
        try {
            $data = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Backup is not valid JSON.', 0, $exception);
        }
        if (!is_array($data)
            || ($data['format'] ?? null) !== 'php-bot-logical-backup'
            || ($data['format_version'] ?? null) !== 1
            || !is_string($data['created_at_utc'] ?? null)
            || !is_array($data['schema_migrations'] ?? null)
            || !is_array($data['tables'] ?? null)
        ) {
            throw new RuntimeException('Backup format or version is not supported.');
        }

        $knownTables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $knownTables = array_fill_keys(array_map('strval', is_array($knownTables) ? $knownTables : []), true);
        $rowCount = 0;
        $tableCount = 0;
        $omitted = [];

        foreach ($data['tables'] as $table => $tableData) {
            if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table) || !is_array($tableData)) {
                throw new RuntimeException('Backup contains an invalid table entry.');
            }
            if (!isset($knownTables[$table])) {
                throw new RuntimeException('Backup references a table not present in the current database: ' . $table);
            }
            $rows = $tableData['rows'] ?? null;
            $declaredCount = $tableData['row_count'] ?? null;
            if (!is_array($rows) || !is_int($declaredCount) || $declaredCount !== count($rows)) {
                throw new RuntimeException('Backup row count does not match its data for table: ' . $table);
            }

            $columns = $this->pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
            $allowedColumns = array_fill_keys(array_map('strval', is_array($columns) ? $columns : []), true);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new RuntimeException('Backup contains a malformed row in table: ' . $table);
                }
                foreach ($row as $column => $_value) {
                    if (!is_string($column) || !isset($allowedColumns[$column])) {
                        throw new RuntimeException('Backup contains an unknown column in table: ' . $table);
                    }
                }
            }
            $tableCount++;
            $rowCount += count($rows);
            if ($table === 'bot_settings') {
                $currentSettings = $this->pdo->query('SELECT setting_key FROM bot_settings')->fetchAll(PDO::FETCH_COLUMN);
                $exportedSettings = array_column($rows, 'setting_key');
                $missing = array_diff(array_map('strval', is_array($currentSettings) ? $currentSettings : []), array_map('strval', is_array($exportedSettings) ? $exportedSettings : []));
                $omitted = array_values(array_filter(array_map('strval', $missing), static function (string $key): bool {
                    return (bool) preg_match('/(?:token|secret|password|passwd|api[_-]?key|private[_-]?key|credential|webhook)/i', $key);
                }));
            }
        }

        return [
            'format_version' => 1,
            'created_at_utc' => $data['created_at_utc'],
            'table_count' => $tableCount,
            'row_count' => $rowCount,
            'omitted_tables' => $omitted,
        ];
    }

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
