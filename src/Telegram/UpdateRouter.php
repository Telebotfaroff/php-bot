<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Database\SettingsRepository;
use App\Security\AdminAuthorizer;

final class UpdateRouter
{
    public function __construct(
        private readonly BotApiClient $telegram,
        private readonly SettingsRepository $settings,
        private readonly AdminAuthorizer $admin
    ) {
    }

    /** @param array<string, mixed> $update */
    public function dispatch(array $update): void
    {
        $context = UpdateContext::from($update);

        if ($context->isCallback) {
            if ($context->callbackId !== null) {
                $this->telegram->answerCallbackQuery($context->callbackId, [
                    'text' => 'This action is not available yet.',
                ]);
            }
            return;
        }

        if ($context->chatId === null || $context->text === null || $context->userId === null) {
            return;
        }

        $parts = preg_split('/\s+/', trim($context->text), 2);
        $rawCommand = is_array($parts) ? ($parts[0] ?? '') : '';
        $command = strtolower((string) preg_replace('/@\w+$/', '', $rawCommand));

        if ($command === '/start') {
            $name = $update['message']['from']['first_name'] ?? 'there';
            $name = is_string($name) ? preg_replace('/[\x00-\x1F\x7F]/u', '', $name) : 'there';
            $name = trim(is_string($name) ? $name : 'there');
            if ($name === '') {
                $name = 'there';
            }
            $this->telegram->sendMessage(
                $context->chatId,
                'Hey ' . $name . "! 👋\n\nWelcome to the File Store Bot.\nUse /help to see available commands."
            );
            return;
        }

        if ($command === '/help') {
            $this->telegram->sendMessage(
                $context->chatId,
                "Available commands:\n/start — Start the bot\n/help — Show help\n/id — Show your Telegram user ID"
            );
            return;
        }

        if ($command === '/settings' || $command === '/setting') {
            if (!$this->admin->isOwner($context->userId)) {
                $this->telegram->sendMessage($context->chatId, '⛔ This command is available to the bot owner only.');
                return;
            }

            $current = $this->settings->all();
            $lines = ['⚙️ Bot Settings (read-only for now)', ''];
            if ($current === []) {
                $lines[] = 'No settings have been saved yet.';
            } else {
                foreach ($current as $key => $value) {
                    $safeValue = preg_match('/token|secret|password|address|upi|crypto/i', $key)
                        ? '[hidden]' : (function_exists('mb_substr') ? mb_substr($value, 0, 160) : substr($value, 0, 160));
                    $lines[] = $key . ': ' . $safeValue;
                }
            }
            $this->telegram->sendMessage($context->chatId, implode("\n", $lines));
            return;
        }

        if ($command === '/id') {
            $this->telegram->sendMessage($context->chatId, 'Your Telegram user ID: ' . $context->userId);
        }
    }
}
