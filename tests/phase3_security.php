<?php

declare(strict_types=1);

use App\Security\AdminAuthorizer;
use App\Security\ShortenerCallbackVerifier;
use App\Telegram\UpdateContext;

putenv('BOT_OWNER_ID=123456789');
require dirname(__DIR__) . '/src/Bootstrap.php';

function phase3Check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$authorizer = new AdminAuthorizer();
phase3Check($authorizer->isOwner(123456789), 'accepts configured owner ID');
phase3Check(!$authorizer->isOwner(123456788), 'rejects a different user ID');
phase3Check(!$authorizer->isOwner(null), 'rejects a missing user ID');

$context = UpdateContext::from([
    'update_id' => 101,
    'message' => [
        'text' => '/start',
        'chat' => ['id' => 123456789],
        'from' => ['id' => 123456789],
    ],
]);
phase3Check($context->updateId === 101, 'extracts update ID');
phase3Check($context->chatId === 123456789, 'extracts chat ID');
phase3Check($context->userId === 123456789, 'extracts sender ID');
phase3Check($context->text === '/start' && !$context->isCallback, 'extracts message command');

$callback = UpdateContext::from([
    'update_id' => 102,
    'callback_query' => ['id' => 'callback-test', 'data' => 'future_action', 'from' => ['id' => 123456789]],
]);
phase3Check($callback->isCallback && $callback->callbackData === 'future_action', 'identifies callback updates');

echo "Phase 3 authorization/context tests passed.\n";
