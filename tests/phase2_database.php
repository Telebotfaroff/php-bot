<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\MigrationRunner;
use App\Database\UpdateProcessor;
use App\Database\SettingsRepository;
use App\Services\WalletService;

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

check(count($firstRun) === 6, 'applies all migrations present on first run');
check($secondRun === [], 'migration runner is idempotent');

foreach ([
    'bot_settings',
    'wallet_accounts',
    'wallet_ledger',
    'premium_subscriptions',
    'collection_delivery_jobs',
    'collection_delivery_items',
    'delivery_outgoing_messages',
    'wallet_verification_tasks',
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

echo "
$settings = new SettingsRepository($pdo);
$wallet = new WalletService($pdo, $settings);
$walletUser = random_int(900000000, 999999999);
$settings->set('welcome_coins', '25');
check($wallet->grantWelcomeReward($walletUser) === 25, 'credits configured welcome coins');
check($wallet->grantWelcomeReward($walletUser) === 25, 'welcome reward is idempotent');
$settings->set('welcome_coins', '99');
check($wallet->grantWelcomeReward($walletUser) === 25, 'welcome reward remains one-time after setting changes');

$ledgerCount = $pdo->prepare(
    "SELECT COUNT(*) FROM wallet_ledger WHERE telegram_user_id = :user AND reference_key = 'welcome-bonus-v1'"
);
$ledgerCount->execute(['user' => $walletUser]);
check((int) $ledgerCount->fetchColumn() === 1, 'records one welcome ledger entry');

$settings->set('shortener_provider_code', 'testprovider');
$settings->set('shortener_reward_coins', '10');
$task = $wallet->createVerificationTask($walletUser);
check(is_array($task) && strlen($task['token']) === 64, 'creates a unique verification task token');
check($wallet->creditVerifiedTask($task['token'], 'wrong-provider') === false, 'rejects verification from the wrong provider');
check($wallet->balance($walletUser) === 25, 'does not credit unverified task');
check($wallet->creditVerifiedTask($task['token'], 'testprovider'), 'credits task after trusted provider verification');
check(!$wallet->creditVerifiedTask($task['token'], 'testprovider'), 'does not credit repeated verification callback');
check($wallet->balance($walletUser) === 35, 'wallet balance reflects exactly one verified reward');

Phase 2 database tests passed.\n";
