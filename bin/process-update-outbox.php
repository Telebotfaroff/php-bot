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
    $router = new UpdateRouter($telegram, $settings, new AdminAuthorizer(), $wizard, $delivery);
    $completed = (new UpdateOutbox($pdo, $router))->dispatchDue(25);
    fwrite(STDOUT, 'Outbox jobs completed: ' . $completed . PHP_EOL);
} catch (Throwable $exception) {
    Logger::exception('Update outbox cron failed', $exception);
    fwrite(STDERR, "Outbox worker failed. Check the private application log.\n");
    exit(1);
}
