<?php

declare(strict_types=1);

putenv('WEBHOOK_SECRET=phase1-test-secret');

require dirname(__DIR__) . '/src/Bootstrap.php';

$checks = [
    'accepts matching secret' => \App\Security\WebhookVerifier::isValid('phase1-test-secret'),
    'rejects incorrect secret' => !\App\Security\WebhookVerifier::isValid('wrong-secret'),
    'rejects missing secret' => !\App\Security\WebhookVerifier::isValid(null),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
    if (!$passed) {
        $failed[] = $name;
    }
}

if ($failed !== []) {
    exit(1);
}

echo 'Phase 1 smoke tests passed.' . PHP_EOL;
