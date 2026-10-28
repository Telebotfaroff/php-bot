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
use App\Services\WalletService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/Bootstrap.php';

try {
    $pdo = Connection::make();
    $telegram = new BotApiClient();
    $settings = new SettingsRepository($pdo);
    $wizard = new PostWizardService($pdo, $telegram, $settings);
    $delivery = new CollectionDeliveryService($pdo, $telegram, $settings);
    $wallet = new WalletService($pdo, $settings);
    $premium = new PremiumService($pdo);
    $router = new UpdateRouter($telegram, $settings, new AdminAuthorizer(), $wizard, $delivery, $wallet, $premium);
    $completed = (new UpdateOutbox($pdo, $router))->dispatchDue(25);
    fwrite(STDOUT, 'Outbox jobs completed: ' . $completed . PHP_EOL);
} catch (Throwable $exception) {
    Logger::exception('Update outbox cron failed', $exception);
    fwrite(STDERR, "Outbox worker failed. Check the private application log.\n");
    exit(1);
}
