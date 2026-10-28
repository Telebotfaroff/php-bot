<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Config;
use PDO;
use RuntimeException;

final class Connection
{
    public static function make(): PDO
    {
        $dsn = Config::get('DB_DSN');
        if (is_string($dsn) && trim($dsn) !== '') {
            $dsn = trim($dsn);
        } else {
            $host = Config::requireString('DB_HOST');
            $name = Config::requireString('DB_NAME');
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

        $password = Config::get('DB_PASSWORD', '');
        if (!is_string($password)) {
            throw new RuntimeException('DB_PASSWORD must be a string.');
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        $sslMode = strtolower((string) Config::get('DB_SSL_MODE', 'disabled'));
        if (!in_array($sslMode, ['disabled', 'required'], true)) {
            throw new RuntimeException('DB_SSL_MODE must be disabled or required.');
        }

        if ($sslMode === 'required') {
            if (!defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                throw new RuntimeException('PDO MySQL SSL options are unavailable in this PHP runtime.');
            }

            $caFile = Config::get('DB_SSL_CA');
            if (is_string($caFile) && trim($caFile) !== '') {
                if (!is_file($caFile) || !is_readable($caFile)) {
                    throw new RuntimeException('DB_SSL_CA must point to a readable CA certificate file.');
                }
                $options[PDO::MYSQL_ATTR_SSL_CA] = $caFile;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            } else {
                // Encrypt the connection when the provider requires TLS. For strict
                // server identity verification, configure DB_SSL_CA with the provider CA.
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
        }

        return new PDO($dsn, (string) Config::get('DB_USER', ''), $password, $options);
    }
}
