<?php

declare(strict_types=1);

namespace App\Telegram;

final class UpdateContext
{
    /** @param array<string, mixed> $update */
    public static function from(array $update): self
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        $callback = $update['callback_query'] ?? null;

        $from = is_array($message) ? ($message['from'] ?? null)
            : (is_array($callback) ? ($callback['from'] ?? null) : null);
        $chat = is_array($message) ? ($message['chat'] ?? null) : null;

        $text = is_array($message) && is_string($message['text'] ?? null)
            ? $message['text'] : null;

        return new self(
            updateId: (int) ($update['update_id'] ?? 0),
            userId: is_array($from) && is_numeric($from['id'] ?? null) ? (int) $from['id'] : null,
            chatId: is_array($chat) && is_numeric($chat['id'] ?? null) ? (int) $chat['id'] : null,
            text: $text,
            isCallback: is_array($callback),
            callbackId: is_array($callback) && is_string($callback['id'] ?? null) ? $callback['id'] : null,
            callbackData: is_array($callback) && is_string($callback['data'] ?? null) ? $callback['data'] : null,
        );
    }

    private function __construct(
        public readonly int $updateId,
        public readonly ?int $userId,
        public readonly ?int $chatId,
        public readonly ?string $text,
        public readonly bool $isCallback,
        public readonly ?string $callbackId,
        public readonly ?string $callbackData,
    ) {
    }
}
