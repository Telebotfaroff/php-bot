<?php

declare(strict_types=1);

use App\Config\Config;
use App\Database\Connection;
use App\Database\SettingsRepository;
use App\Security\ShortenerCallbackVerifier;
use App\Services\WalletService;
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
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '' || strlen($raw) > 16384) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = [];
        parse_str($raw, $payload);
    }
    $provider = is_string($payload['provider_code'] ?? null) ? trim($payload['provider_code']) : '';
    $token = is_string($payload['token'] ?? null) ? trim($payload['token']) : '';
    $timestamp = is_string($_SERVER['HTTP_X_SHORTENER_TIMESTAMP'] ?? null)
        ? trim($_SERVER['HTTP_X_SHORTENER_TIMESTAMP']) : '';
    $signature = is_string($_SERVER['HTTP_X_SHORTENER_SIGNATURE'] ?? null)
        ? trim($_SERVER['HTTP_X_SHORTENER_SIGNATURE']) : '';

    $secret = Config::requireString('SHORTENER_CALLBACK_SECRET');
    $pdo = Connection::make();
    $settings = new SettingsRepository($pdo);
    $expectedProvider = trim((string) $settings->get('shortener_provider_code', ''));

    if ($expectedProvider === '' || !ShortenerCallbackVerifier::verify(
        $provider, $token, $timestamp, $signature, $expectedProvider, $secret
    )) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid callback authentication']);
        exit;
    }

    $credited = (new WalletService($pdo, $settings))->creditVerifiedTask($token, $provider);
    // Both a newly credited event and a replay are acknowledged. The wallet ledger remains idempotent.
    http_response_code(200);
    echo json_encode(['ok' => true, 'credited' => $credited]);
} catch (Throwable $exception) {
    Logger::exception('Shortener callback failed', $exception);
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Service unavailable']);
}
