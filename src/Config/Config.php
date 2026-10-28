<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final class Config
{
    private static ?array $values = null;

    public static function all(): array
    {
        if (self::$values !== null) {
            return self::$values;
        }

        $fileValues = [];
        $configFile = getenv('BOT_CONFIG_FILE');
        if (!is_string($configFile) || $configFile === '') {
            // Default private location: sibling of the project directory, outside public_html
            // when the project itself is deployed outside the document root.
            $configFile = dirname(__DIR__, 3) . '/private/php-bot-config.php';
        }
        if (is_string($configFile) && $configFile !== '' && is_file($configFile)) {
            $loaded = require $configFile;
            if (!is_array($loaded)) {
                throw new RuntimeException('BOT_CONFIG_FILE must return an array.');
            }
            $fileValues = $loaded;
        }

        $keys = [
            'BOT_TOKEN', 'BOT_USERNAME', 'BOT_OWNER_ID', 'WEBHOOK_SECRET', 'SHORTENER_CALLBACK_SECRET',
            'WEBHOOK_URL', 'APP_ENV', 'LOG_FILE', 'TELEGRAM_API_BASE',
            'DB_DSN', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_CHARSET',
            'DB_SSL_MODE', 'DB_SSL_CA', 'AUTO_MIGRATE',
        ];
        $environment = [];
        foreach ($keys as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                $environment[$key] = $value;
            }
        }

        self::$values = array_merge($fileValues, $environment);
        return self::$values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function requireString(string $key): string
    {
        $value = self::get($key);
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Missing required configuration: ' . $key);
        }
        return trim($value);
    }

    public static function isProduction(): bool
    {
        return strtolower((string) self::get('APP_ENV', 'production')) === 'production';
    }
}
