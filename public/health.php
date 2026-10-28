<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if (PHP_VERSION_ID < 80100 || !extension_loaded('curl') || !extension_loaded('pdo_mysql')) {
    http_response_code(503);
    echo "unhealthy\n";
    exit;
}

http_response_code(200);
echo "ok\n";
