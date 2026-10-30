<?php

declare(strict_types=1);

use App\Config\Config;
use App\Database\Connection;
use App\Database\SettingsRepository;
use App\Telegram\BotApiClient;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require dirname(__DIR__) . '/src/Bootstrap.php';

$failed = false;
$check = static function (string $label, callable $operation) use (&$failed): void {
    try {
        $detail = $operation();
        fwrite(STDOUT, "[PASS] {$label}" . ($detail !== null && $detail !== '' ? ": {$detail}" : '') . PHP_EOL);
    } catch (Throwable $exception) {
        $failed = true;
        fwrite(STDOUT, "[FAIL] {$label}: {$exception->getMessage()}" . PHP_EOL);
    }
};

$pdo = null;
$settings = null;

$check('PHP runtime and extensions', static function (): string {
    if (PHP_VERSION_ID < 80100) {
        throw new RuntimeException('PHP 8.1 or newer is required; current version is ' . PHP_VERSION . '.');
    }
    foreach (['curl', 'pdo_pgsql'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException("Missing PHP extension: {$extension}.");
        }
    }
    return PHP_VERSION;
});

$check('Database connection', static function () use (&$pdo, &$settings): string {
    $pdo = Connection::make();
    $pdo->query('SELECT 1')->fetchColumn();
    $settings = new SettingsRepository($pdo);
    return 'connected';
});

$check('Required auto-delete schema', static function () use (&$pdo): string {
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection was not established.');
    }

    $required = [
        'bot_settings' => ['setting_key', 'setting_value'],
        'delivery_outgoing_messages' => [
            'id', 'chat_id', 'message_id', 'created_at',
            'auto_delete_attempts', 'auto_delete_last_error',
        ],
    ];

    foreach ($required as $table => $columns) {
        $statement = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->execute(['table' => $table]);
        $found = array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), true);
        if ($found === []) {
            throw new RuntimeException("Table {$table} is missing; run php bin/migrate.php.");
        }
        foreach ($columns as $column) {
            if (!isset($found[$column])) {
                throw new RuntimeException("Column {$table}.{$column} is missing; run php bin/migrate.php.");
            }
        }
    }
    return 'migration 009 columns present';
});

$check('Telegram bot token', static function (): string {
    $me = (new BotApiClient())->getMe();
    $user = $me['result'] ?? [];
    if (!is_array($user) || !isset($user['id'], $user['username'])) {
        throw new RuntimeException('Telegram getMe returned an unexpected response.');
    }
    return 'authenticated as @' . $user['username'];
});

$check('Auto-delete settings', static function () use (&$settings): string {
    if (!$settings instanceof SettingsRepository) {
        throw new RuntimeException('Database settings could not be loaded.');
    }
    $enabled = filter_var($settings->get('auto_delete_enabled', '0'), FILTER_VALIDATE_BOOLEAN);
    $seconds = filter_var($settings->get('auto_delete_after_seconds', '86400'), FILTER_VALIDATE_INT);
    if ($seconds === false || $seconds < 60 || $seconds > 31536000) {
        throw new RuntimeException('auto_delete_after_seconds must be between 60 and 31536000.');
    }
    return ($enabled ? 'enabled' : 'disabled') . '; delay ' . $seconds . ' seconds';
});

if ($failed) {
    fwrite(STDERR, "Preflight failed. Fix the reported items, then run this command again. No messages were deleted.\n");
    exit(1);
}

fwrite(STDOUT, "Preflight passed. This check did not delete any Telegram messages.\n");
