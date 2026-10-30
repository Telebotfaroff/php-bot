<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Config;
use PDO;
use RuntimeException;

final class Connection
{
    private static ?string $generatedCaPath = null;

    public static function make(): PDO
    {
        $dbUrl = Config::get('DB_URL');
        $urlOptions = [];

        if (is_string($dbUrl) && trim($dbUrl) !== '') {
            $parts = parse_url(trim($dbUrl));
            if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'mysql') {
                throw new RuntimeException('DB_URL must be a valid mysql:// connection URL.');
            }

            $host = (string) ($parts['host'] ?? '');
            $port = (string) ($parts['port'] ?? '3306');
            $name = ltrim((string) ($parts['path'] ?? ''), '/');
            $urlOptions = [];
            parse_str((string) ($parts['query'] ?? ''), $urlOptions);

            if ($host === '' || $name === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                throw new RuntimeException('DB_URL must include a valid host, port, and database name.');
            }

            $user = rawurldecode((string) ($parts['user'] ?? ''));
            $password = rawurldecode((string) ($parts['pass'] ?? ''));
            if ($user === '') {
                throw new RuntimeException('DB_URL must include a database username.');
            }

            $charset = (string) Config::get('DB_CHARSET', 'utf8mb4');
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $charset)) {
                throw new RuntimeException('DB_CHARSET contains unsupported characters.');
            }
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);
        } else {
            $dsn = Config::get('DB_DSN');
            if (is_string($dsn) && trim($dsn) !== '') {
                $dsn = trim($dsn);
            } else {
                $host = Config::requireString('DB_HOST');
                $name = Config::requireString('DB_NAME');
                $user = Config::requireString('DB_USER');
                $password = Config::get('DB_PASSWORD', '');
                $port = (string) Config::get('DB_PORT', '3306');
                $charset = (string) Config::get('DB_CHARSET', 'utf8mb4');

                if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                    throw new RuntimeException('DB_PORT must be a valid TCP port.');
                }
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $charset)) {
                    throw new RuntimeException('DB_CHARSET contains unsupported characters.');
                }

                $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);
            }
        }

        if (!isset($user)) {
            $user = (string) Config::get('DB_USER', '');
        }
        if (!isset($password)) {
            $password = Config::get('DB_PASSWORD', '');
        }
        if (!is_string($password)) {
            throw new RuntimeException('DB_PASSWORD must be a string.');
        }

        $sslMode = strtolower((string) Config::get(
            'DB_SSL_MODE',
            $urlOptions['ssl-mode'] ?? $urlOptions['ssl_mode'] ?? 'disabled'
        ));
        if (!in_array($sslMode, ['disabled', 'required', 'verify-ca'], true)) {
            throw new RuntimeException('DB_SSL_MODE must be disabled, required, or verify-ca.');
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        if ($sslMode !== 'disabled') {
            if (!defined('PDO::MYSQL_ATTR_SSL_CA') || !defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                throw new RuntimeException('PDO MySQL SSL options are unavailable in this PHP runtime.');
            }

            $caFile = self::resolveCaFile();
            if ($sslMode === 'verify-ca' && $caFile === null) {
                throw new RuntimeException('DB_SSL_CA or DB_SSL_CA_PEM is required when DB_SSL_MODE=verify-ca.');
            }

            if ($caFile !== null) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $caFile;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            } else {
                // REQUIRED encrypts the connection but does not authenticate the server certificate.
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
        }

        return new PDO((string) $dsn, $user, $password, $options);
    }

    private static function resolveCaFile(): ?string
    {
        $caPath = Config::get('DB_SSL_CA');
        if (is_string($caPath) && trim($caPath) !== '') {
            if (!is_file($caPath) || !is_readable($caPath)) {
                throw new RuntimeException('DB_SSL_CA must point to a readable CA certificate file.');
            }
            return $caPath;
        }

        $caPem = Config::get('DB_SSL_CA_PEM');
        if (!is_string($caPem) || trim($caPem) === '') {
            return null;
        }

        if (self::$generatedCaPath !== null && is_file(self::$generatedCaPath)) {
            return self::$generatedCaPath;
        }

        $path = tempnam(sys_get_temp_dir(), 'php-bot-db-ca-');
        if ($path === false || file_put_contents($path, $caPem, LOCK_EX) === false) {
            throw new RuntimeException('Unable to create temporary database CA certificate file.');
        }
        chmod($path, 0600);
        self::$generatedCaPath = $path;
        return $path;
    }
}
