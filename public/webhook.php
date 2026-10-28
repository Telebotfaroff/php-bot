<?php

declare(strict_types=1);

use App\Config\Config;
use App\Database\Connection;
use App\Database\SettingsRepository;
use App\Database\UpdateOutbox;
use App\Database\UpdateProcessor;
use App\Security\AdminAuthorizer;
use App\Security\WebhookVerifier;
use App\Services\CollectionDeliveryService;
use App\Services\PostWizardService;
use App\Services\PremiumService;
use App\Services\WalletService;
use App\Support\Logger;
use App\Telegram\BotApiClient;
use App\Telegram\UpdateRouter;

require dirname(__DIR__) . '/src/Bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    Config::requireString('BOT_TOKEN');
    Config::requireString('WEBHOOK_SECRET');

    $providedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null;
    if (!WebhookVerifier::isValid(is_string($providedSecret) ? $providedSecret : null)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 1048576) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request body']);
        exit;
    }

    $update = json_decode($rawBody, true);
    $updateId = is_array($update) ? filter_var($update['update_id'] ?? null, FILTER_VALIDATE_INT) : false;
    if (!is_array($update) || $updateId === false || $updateId === null || $updateId < 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid Telegram update']);
        exit;
    }

    $pdo = Connection::make();
    $processor = new UpdateProcessor($pdo);
    $isNewUpdate = $processor->process($update);

    // Always retry the durable job, including when Telegram redelivers a duplicate update.
    $telegram = new BotApiClient();
    $settings = new SettingsRepository($pdo);
    $wizard = new PostWizardService($pdo, $telegram, $settings);
    $delivery = new CollectionDeliveryService($pdo, $telegram, $settings);
    $wallet = new WalletService($pdo, $settings);
    $premium = new PremiumService($pdo);
    $router = new UpdateRouter($telegram, $settings, new AdminAuthorizer(), $wizard, $delivery, $wallet, $premium);
    $outbox = new UpdateOutbox($pdo, $router);
    $dispatched = $outbox->dispatchOne((int) $updateId);

    Logger::info('Telegram update accepted by durable outbox', [
        'update_id' => (int) $updateId,
        'new_update' => $isNewUpdate,
        'dispatched_now' => $dispatched,
    ]);

    http_response_code(200);
    echo json_encode(['ok' => true]);
} catch (Throwable $exception) {
    Logger::exception('Webhook could not persist Telegram update', $exception);
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Service unavailable']);
}
