<?php

declare(strict_types=1);

use App\Config\Config;
use App\Database\Connection;
use App\Database\UpdateProcessor;
use App\Security\WebhookVerifier;
use App\Support\Logger;

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

    // Acknowledge quickly. Background workers own all handler execution and retries.
    Logger::info('Telegram update queued', [
        'update_id' => (int) $updateId,
        'new_update' => $isNewUpdate,
    ]);

    http_response_code(200);
    echo json_encode(['ok' => true]);
} catch (Throwable $exception) {
    Logger::exception('Webhook could not persist Telegram update', $exception);
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Service unavailable']);
}
