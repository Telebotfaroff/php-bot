<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Database\SettingsRepository;
use App\Security\AdminAuthorizer;
use App\Services\PostWizardService;

final class UpdateRouter
{
    public function __construct(
        private readonly BotApiClient $telegram,
        private readonly SettingsRepository $settings,
        private readonly AdminAuthorizer $admin,
        private readonly PostWizardService $postWizard
    ) {}

    /** @param array<string, mixed> $update */
    public function dispatch(array $update): void
    {
        $context = UpdateContext::from($update);

        if ($context->isCallback) {
            if ($context->callbackData !== null && str_starts_with($context->callbackData, 'post:')) {
                if (!$this->admin->isOwner($context->userId)) {
                    if ($context->callbackId !== null) {
                        $this->telegram->answerCallbackQuery($context->callbackId, ['text' => 'Owner only.']);
                    }
                    return;
                }
                $this->postWizard->handleCallback($update, (int) $context->userId, $context->callbackData);
                return;
            }
            if ($context->callbackId !== null) {
                $this->telegram->answerCallbackQuery($context->callbackId, [
                    'text' => 'This action is not available yet.',
                ]);
            }
            return;
        }

        if ($context->chatId === null || $context->userId === null) return;

        $parts = preg_split('/\s+/', trim((string) $context->text), 2);
        $rawCommand = is_array($parts) ? ($parts[0] ?? '') : '';
        $command = strtolower((string) preg_replace('/@\w+$/', '', $rawCommand));
        $argument = is_array($parts) ? trim((string) ($parts[1] ?? '')) : '';

        if ($command === '/post') {
            if (!$this->requireOwner($context->userId, $context->chatId)) return;
            $this->postWizard->begin($context->userId, $context->chatId);
            return;
        }

        if (($command === '/setting' || $command === '/settings') && preg_match('/^set\s+(dump_channel_id|post_channel_id)\s+(.+)$/i', $argument, $match)) {
            if (!$this->requireOwner($context->userId, $context->chatId)) return;
            $key = strtolower($match[1]);
            $value = trim($match[2]);
            if (!preg_match('/^(?:-?[0-9]{5,}|@[A-Za-z][A-Za-z0-9_]{4,})$/', $value)) {
                $this->telegram->sendMessage($context->chatId,
                    'Use a numeric Telegram channel ID (usually starts with -100) or a public @channelusername.');
                return;
            }
            $this->settings->set($key, $value, $context->userId);
            $this->telegram->sendMessage($context->chatId, '✅ Saved ' . $key . '. Make sure the bot is an administrator of that channel.');
            return;
        }

        if ($command === '/start') {
            $name = $update['message']['from']['first_name'] ?? 'there';
            $name = is_string($name) ? preg_replace('/[\x00-\x1F\x7F]/u', '', $name) : 'there';
            $name = trim(is_string($name) ? $name : 'there');
            if ($name === '') $name = 'there';
            $this->telegram->sendMessage(
                $context->chatId,
                'Hey ' . $name . "! 👋\n\nWelcome to the File Store Bot.\nUse /help to see available commands."
            );
            return;
        }

        if ($command === '/help') {
            $this->telegram->sendMessage(
                $context->chatId,
                "Available commands:\n/start — Start the bot\n/help — Show help\n/id — Show your Telegram user ID\n/post — Start an admin collection draft\n/done — Preview videos collected in the current draft\n/cancel — Cancel the current draft\n/skip — Skip optional thumbnail\n/setting set dump_channel_id CHANNEL_ID — Configure dump channel\n/setting set post_channel_id CHANNEL_ID — Configure post channel"
            );
            return;
        }

        if ($command === '/settings' || $command === '/setting') {
            if (!$this->requireOwner($context->userId, $context->chatId)) return;
            $current = $this->settings->all();
            $lines = ['⚙️ Bot Settings', ''];
            if ($current === []) {
                $lines[] = 'No settings have been saved yet.';
            } else {
                foreach ($current as $key => $value) {
                    $safeValue = preg_match('/token|secret|password|address|upi|crypto/i', $key)
                        ? '[hidden]' : (function_exists('mb_substr') ? mb_substr($value, 0, 160) : substr($value, 0, 160));
                    $lines[] = $key . ': ' . $safeValue;
                }
            }
            $lines[] = '';
            $lines[] = 'Editable channel settings:';
            $lines[] = '/setting set dump_channel_id -1001234567890';
            $lines[] = '/setting set post_channel_id -1001234567890';
            $this->telegram->sendMessage($context->chatId, implode("\n", $lines));
            return;
        }

        if ($command === '/id') {
            $this->telegram->sendMessage($context->chatId, 'Your Telegram user ID: ' . $context->userId);
            return;
        }

        // Wizard messages are handled last so /help and /settings remain usable mid-draft.
        $this->postWizard->handleMessage($update, $context->userId, $context->chatId, $context->text);
    }

    private function requireOwner(int $userId, int $chatId): bool
    {
        if ($this->admin->isOwner($userId)) return true;
        $this->telegram->sendMessage($chatId, '⛔ This command is available to the bot owner only.');
        return false;
    }
}
