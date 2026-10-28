<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Config\Config;
use App\Database\SettingsRepository;
use App\Security\AdminAuthorizer;
use Throwable;

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
                $this->telegram->answerCallbackQuery($context->callbackId, 'This action is not available yet.');
            }
            return;
        }

        if ($context->chatId === null || $context->text === null || $context->userId === null) {
            return;
        }

        $text = trim($context->text);
        $command = strtolower((string) preg_replace('/@\w+$/', '', strtok($text, " \t\r\n") ?: ''));

        if ($command === '/start') {
            $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($update['message']['from']['first_name'] ?? 'there')));
            if ($name === '') {
                $name = 'there';
            }
            $this->telegram->sendMessage(
                $context->chatId,
                "Hey " . $name . "! 👋\n\nWelcome to the File Store Bot.\nCollection delivery and file search will be added in the next phases."
            );
            return;
        }

        if ($command === '/settings' || $command === '/setting') {
            if (!$this->admin->isOwner($context->userId)) {
                $this->telegram->sendMessage($context->chatId, '⛔ This command is available to the bot owner only.');
                return;
            }

            $current = $this->settings->all();
            $lines = ['⚙️ Bot Settings (read-only in this phase)', ''];
            if ($current === []) {
                $lines[] = 'No settings have been saved yet.';
            } else {
                foreach ($current as $key => $value) {
                    $safeValue = preg_match('/token|secret|password|address|upi|crypto/i', $key)
                        ? '[hidden]' : mb_substr($value, 0, 160);
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
