<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\MigrationRunner;
use App\Database\UpdateProcessor;

require dirname(__DIR__) . '/src/Bootstrap.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$pdo = Connection::make();
$migrations = new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations');
$firstRun = $migrations->migrate();
$secondRun = $migrations->migrate();

check(count($firstRun) === 5, 'applies all migrations present on first run');
check($secondRun === [], 'migration runner is idempotent');

foreach ([
    'bot_settings',
    'wallet_accounts',
    'wallet_ledger',
    'premium_subscriptions',
    'collection_delivery_jobs',
    'collection_delivery_items',
    'delivery_outgoing_messages',
] as $table) {
    $tableCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table'
    );
    $tableCheck->execute(['table' => $table]);
    check((int) $tableCheck->fetchColumn() === 1, 'creates ' . $table . ' table');
}

$processor = new UpdateProcessor($pdo);
$updateId = random_int(700000000, 799999999);
$userId = random_int(800000000, 899999999);
$update = [
    'update_id' => $updateId,
    'message' => [
        'from' => [
            'id' => $userId,
            'is_bot' => false,
            'first_name' => 'Phase Two Test',
            'username' => 'phase_two_test',
            'language_code' => 'en',
        ],
    ],
];

check($processor->process($update), 'stores a new Telegram update');
check(!$processor->process($update), 'ignores a duplicate Telegram update');
$jobQuery = $pdo->prepare('SELECT status, payload_json FROM telegram_update_jobs WHERE update_id = :id');
$jobQuery->execute(['id' => $updateId]);
$job = $jobQuery->fetch();
check(is_array($job) && $job['status'] === 'pending', 'persists a pending outbox job for each new update');
$storedPayload = json_decode((string) ($job['payload_json'] ?? ''), true);
check(is_array($storedPayload) && (int) $storedPayload['update_id'] === $updateId, 'outbox preserves the original update payload');

$userQuery = $pdo->prepare('SELECT first_name, username FROM users WHERE telegram_user_id = :id');
$userQuery->execute(['id' => $userId]);
$user = $userQuery->fetch();
check(is_array($user) && $user['first_name'] === 'Phase Two Test', 'stores sender profile');

$updateQuery = $pdo->prepare('SELECT COUNT(*) FROM telegram_updates WHERE update_id = :id');
$updateQuery->execute(['id' => $updateId]);
check((int) $updateQuery->fetchColumn() === 1, 'keeps only one row per Telegram update');

echo "Phase 2 database tests passed.\n";
