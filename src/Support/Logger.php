<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Config;
use Throwable;

final class Logger
{
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function exception(string $message, Throwable $exception): void
    {
        self::write('ERROR', $message, [
            'exception' => get_class($exception),
            'code' => (string) $exception->getCode(),
            'error' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $line = json_encode([
            'time' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($line)) {
            $line = '{"level":"ERROR","message":"Could not encode log record"}';
        }

        $logFile = Config::get('LOG_FILE');
        if (is_string($logFile) && $logFile !== '') {
            $directory = dirname($logFile);
            if (is_dir($directory) && is_writable($directory)) {
                error_log($line . PHP_EOL, 3, $logFile);
                return;
            }
        }
        error_log($line);
    }
}
