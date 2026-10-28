<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Database\SettingsRepository;
use App\Services\AutoDeleteService;
use App\Support\Logger;
use App\Telegram\BotApiClient;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/Bootstrap.php';

try {
    $pdo = Connection::make();
    $settings = new SettingsRepository($pdo);
    $service = new AutoDeleteService($pdo, new BotApiClient(), $settings);
    $result = $service->runBatch(50);
    fwrite(STDOUT, 'Auto-delete batch: ' . json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $exception) {
    Logger::exception('Auto-delete worker failed', $exception);
    fwrite(STDERR, "Auto-delete worker failed. Check the private application log.\n");
    exit(1);
}
