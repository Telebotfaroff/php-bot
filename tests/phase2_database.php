<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\MigrationRunner;
use App\Database\UpdateProcessor;
use App\Database\SettingsRepository;
use App\Services\WalletService;
use App\Services\PremiumService;
use App\Services\ReferralService;

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

check(count($firstRun) === 11, 'applies all migrations present on first run');
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
    'premium_plans',
    'premium_payment_requests',
    'referral_attributions',
    'referral_rewards',
    'bot_search_events',
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

echo PHP_EOL;

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


$premium = new PremiumService($pdo);
$planId = $premium->createPlan('Test Premium', 30, '49.00', 'INR');
check($planId > 0, 'creates a Premium plan');
$paymentUser = random_int(600000000, 699999999);
$requestId = $premium->createRequest($paymentUser, $planId, 'upi');
check($requestId > 0, 'creates a pending manual payment request');
check($premium->submitReference($paymentUser, $requestId, 'TEST-TXN-123'), 'stores a user transaction reference');
check($premium->decide($requestId, 123456, true), 'admin approval activates Premium');
$sub = $pdo->prepare("SELECT COUNT(*) FROM premium_subscriptions WHERE telegram_user_id = :user AND status = 'active' AND expires_at > CURRENT_TIMESTAMP");
$sub->execute(['user' => $paymentUser]);
check((int) $sub->fetchColumn() === 1, 'approved request creates an active subscription');
check(!$premium->decide($requestId, 123456, true), 'duplicate approval cannot grant duplicate Premium');


$referrals = new ReferralService($pdo);
$referrerId = random_int(500000000, 549999999);
$referredId = random_int(550000000, 599999999);
foreach ([[$referrerId, 'Referral Owner'], [$referredId, 'Referral Guest']] as [$id, $firstName]) {
    $processor->process([
        'update_id' => random_int(1000000000, 1999999999),
        'message' => ['from' => ['id' => $id, 'is_bot' => false, 'first_name' => $firstName]],
    ]);
}
$referrals->processStart($referrerId, null);
$qualified = $referrals->processStart($referredId, $referrerId);
check($qualified['is_new'] && $qualified['rewarded'], 'qualifies a new user referral');
$premiumCheck = $pdo->prepare("SELECT COUNT(*) FROM premium_subscriptions WHERE telegram_user_id = :user AND status = 'active' AND expires_at > DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 23 HOUR)");
$premiumCheck->execute(['user' => $referrerId]);
check((int) $premiumCheck->fetchColumn() === 1, 'referral reward grants approximately 24 hours of Premium');
$repeat = $referrals->processStart($referredId, $referrerId);
check(!$repeat['is_new'] && !$repeat['rewarded'], 'repeat start does not issue another referral reward');
$self = $referrals->processStart($referrerId, $referrerId);
check(!$self['rewarded'], 'start without referral does not issue a referral reward');
$stats = $referrals->stats($referrerId);
check($stats['qualified'] === 1 && $stats['rewards'] === 1, 'referral stats count the reward exactly once');


$adminFeatures = new AdminFeatureService($pdo);
$moderatedUser = random_int(400000000, 449999999);
$processor->process([
    'update_id' => random_int(2000000000, 2099999999),
    'message' => ['from' => ['id' => $moderatedUser, 'is_bot' => false, 'first_name' => 'Moderation Test']],
]);
check($adminFeatures->setBanned($moderatedUser, 123456789, true, 'automated test'), 'bans a known user');
check($adminFeatures->isBanned($moderatedUser), 'ban status is enforced by service lookup');
$moderated = $adminFeatures->user($moderatedUser);
check(is_array($moderated) && (int) $moderated['is_banned'] === 1 && $moderated['ban_reason'] === 'automated test', 'user inspection returns ban metadata');
check($adminFeatures->setBanned($moderatedUser, 123456789, false), 'unbans a user');
check(!$adminFeatures->isBanned($moderatedUser), 'unban clears active restriction');
$emptySearch = $adminFeatures->searchCollections($moderatedUser, 'no-result-query');
check($emptySearch === [], 'search returns an empty result set without error');
$searchCount = $pdo->prepare('SELECT COUNT(*) FROM bot_search_events WHERE telegram_user_id = :user');
$searchCount->execute(['user' => $moderatedUser]);
check((int) $searchCount->fetchColumn() === 1, 'search analytics record query hash and result count');
$stats = $adminFeatures->stats();
check(isset($stats['users_total'], $stats['collections'], $stats['files'], $stats['pending_jobs']), 'analytics dashboard aggregates core metrics');

echo 'Phase 2 database tests passed.' . PHP_EOL;
