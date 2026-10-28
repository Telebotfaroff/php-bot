<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require dirname(__DIR__) . '/src/Bootstrap.php';

use App\Database\Connection;
use App\Database\MigrationRunner;

try {
    $pdo = Connection::make();
    $runner = new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations');
    $applied = $runner->migrate();

    if ($applied === []) {
        echo "Database schema is already up to date.\n";
    } else {
        foreach ($applied as $migration) {
            echo "Applied: {$migration}\n";
        }
        echo "Database migrations completed.\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
