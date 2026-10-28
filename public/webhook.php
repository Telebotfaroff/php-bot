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
    if (!is_array($update) || !isset($update['update_id']) || !is_numeric($update['update_id'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid Telegram update']);
        exit;
    }

    // Phase 2 persists each update once and records the sender when available.
    // Command handling is added in a later phase.
    $processor = new UpdateProcessor(Connection::make());
    $isNewUpdate = $processor->process($update);
    Logger::info($isNewUpdate ? 'Telegram update stored' : 'Duplicate Telegram update ignored', [
        'update_id' => (int) $update['update_id'],
    ]);

    http_response_code(200);
    echo json_encode(['ok' => true]);
} catch (Throwable $exception) {
    Logger::exception('Webhook processing failed', $exception);
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Service unavailable']);
}
