<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use InvalidArgumentException;

final class SettingsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        self::validateKey($key);
        $statement = $this->pdo->prepare('SELECT setting_value FROM bot_settings WHERE setting_key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return $value === false ? $default : (string) $value;
    }

    public function set(string $key, string $value, ?int $updatedBy = null): void
    {
        self::validateKey($key);
        if (strlen($value) > 10000) {
            throw new InvalidArgumentException('Setting value exceeds 10000 bytes.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO bot_settings (setting_key, setting_value, updated_by)
             VALUES (:key, :value, :updated_by)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                 updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'key' => $key,
            'value' => $value,
            'updated_by' => $updatedBy,
        ]);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $statement = $this->pdo->query('SELECT setting_key, setting_value FROM bot_settings ORDER BY setting_key');
        $settings = [];
        foreach ($statement->fetchAll() as $row) {
            $settings[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return $settings;
    }

    private static function validateKey(string $key): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,99}$/', $key)) {
            throw new InvalidArgumentException('Invalid setting key.');
        }
    }
}
