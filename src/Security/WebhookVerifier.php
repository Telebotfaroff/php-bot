<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\Config;

final class WebhookVerifier
{
    public static function isValid(?string $providedSecret): bool
    {
        $expectedSecret = Config::get('WEBHOOK_SECRET');
        if (!is_string($expectedSecret) || $expectedSecret === '' ||
            !is_string($providedSecret) || $providedSecret === '') {
            return false;
        }
        return hash_equals($expectedSecret, $providedSecret);
    }
}
