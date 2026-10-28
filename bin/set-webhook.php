<?php

declare(strict_types=1);

use App\Config\Config;
use App\Telegram\BotApiClient;

require dirname(__DIR__) . '/src/Bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $url = Config::requireString('WEBHOOK_URL');
    $secret = Config::requireString('WEBHOOK_SECRET');
    (new BotApiClient())->setWebhook($url, $secret);
    fwrite(STDOUT, "Webhook configured successfully." . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Webhook setup failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
