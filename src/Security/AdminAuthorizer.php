<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\Config;

final class AdminAuthorizer
{
    public function isOwner(?int $telegramUserId): bool
    {
        if ($telegramUserId === null || $telegramUserId < 1) {
            return false;
        }

        $configured = Config::get('BOT_OWNER_ID');
        if (!is_string($configured) && !is_int($configured)) {
            return false;
        }
        $ownerId = filter_var((string) $configured, FILTER_VALIDATE_INT);
        return $ownerId !== false && $ownerId > 0 && $telegramUserId === $ownerId;
    }
}
