<?php

declare(strict_types=1);

namespace App\Telegram;

use RuntimeException;

final class BotApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $telegramErrorCode = null,
        public readonly mixed $telegramResponse = null
    ) {
        parent::__construct($message, $telegramErrorCode ?? 0);
    }
}
