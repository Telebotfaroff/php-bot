<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\SettingsRepository;
use App\Database\UpdateOutbox;
use App\Security\AdminAuthorizer;
use App\Telegram\BotApiClient;
use App\Telegram\UpdateRouter;
use App\Support\Logger;
use App\Services\CollectionDeliveryService;
use App\Services\PostWizardService;
use App\Services\PremiumService;
use App\Services\ReferralService;
use App\Services\WalletService;
use App\Services\AdminFeatureService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/Bootstrap.php';

$daemon = in_array('--daemon', $argv ?? [], true);

try {
    // Keep the DB connection and service graph warm instead of booting PHP every five seconds.
    $pdo = Connection::make();
    $telegram = new BotApiClient();
    $settings = new SettingsRepository($pdo);
    $wizard = new PostWizardService($pdo, $telegram, $settings);
    $delivery = new CollectionDeliveryService($pdo, $telegram, $settings);
    $wallet = new WalletService($pdo, $settings);
    $premium = new PremiumService($pdo);
    $referrals = new ReferralService($pdo, $settings);
    $router = new UpdateRouter($telegram, $settings, new AdminAuthorizer(), $wizard, $delivery, $wallet, $premium, $referrals, new AdminFeatureService($pdo));
    $outbox = new UpdateOutbox($pdo, $router);

    do {
        try {
            $completed = $outbox->dispatchDue(25);
            if (!$daemon) {
                fwrite(STDOUT, 'Outbox jobs completed: ' . $completed . PHP_EOL);
                break;
            }
            // Back off briefly when idle; immediately continue when the batch is full.
            if ($completed < 25) usleep(500000);
        } catch (Throwable $exception) {
            Logger::exception('Update outbox batch failed', $exception);
            if (!$daemon) {
                fwrite(STDERR, "Outbox worker failed. Check the private application log.\n");
                exit(1);
            }
            sleep(2);
        }
    } while ($daemon);
} catch (Throwable $exception) {
    Logger::exception('Update outbox worker could not initialize', $exception);
    fwrite(STDERR, "Outbox worker initialization failed. Check the private application log.\n");
    exit(1);
}
