<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Database\SettingsRepository;
use App\Security\AdminAuthorizer;
use App\Services\CollectionDeliveryService;
use App\Services\PostWizardService;
use App\Services\WalletService;

final class UpdateRouter
{
    public function __construct(
        private readonly BotApiClient $telegram,
        private readonly SettingsRepository $settings,
        private readonly AdminAuthorizer $admin,
        private readonly PostWizardService $postWizard,
        private readonly CollectionDeliveryService $collectionDelivery,
        private readonly WalletService $wallet
    ) {}

    /** @param array<string, mixed> $update */
    public function dispatch(array $update): void
    {
        $context = UpdateContext::from($update);

        if ($context->isCallback) {
            if ($context->callbackData === 'wallet:get_coins') {
                if ($context->callbackId !== null) {
                    $this->telegram->answerCallbackQuery($context->callbackId, ['text' => 'Coin verification provider is not configured yet.']);
                }
                if ($context->userId !== null) {
                    $this->telegram->sendMessage($this->callbackChatId($update, $context->userId),
                        '🪙 Coin rewards are being configured. No coins have been credited. Please try again later.');
                }
                return;
            }
            if ($context->callbackData === 'premium:plans') {
                if ($context->callbackId !== null) {
                    $this->telegram->answerCallbackQuery($context->callbackId, ['text' => 'Premium plans are not available yet.']);
                }
                if ($context->userId !== null) {
                    $this->telegram->sendMessage($this->callbackChatId($update, $context->userId),
                        '⭐ Premium plan selection is not available yet. Your wallet balance has not changed.');
                }
                return;
            }
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
                    'text' => 'Please reopen the collection link after completing the required action.',
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

        if (($command === '/setting' || $command === '/settings') && preg_match('/^set\s+(dump_channel_id|post_channel_id|force_subscribe_channel_id|collection_access_cost|welcome_coins|shortener_reward_coins|shortener_provider_code)\s+(.+)$/i', $argument, $match)) {
            if (!$this->requireOwner($context->userId, $context->chatId)) return;
            $key = strtolower($match[1]);
            $value = trim($match[2]);
            if (in_array($key, ['collection_access_cost', 'welcome_coins', 'shortener_reward_coins'], true)) {
                if (!preg_match('/^(0|[1-9][0-9]{0,8})$/', $value)) {
                    $this->telegram->sendMessage($context->chatId, $key . ' must be a whole number from 0 to 999999999.');
                    return;
                }
            } elseif ($key === 'shortener_provider_code') {
                if (!preg_match('/^[a-z][a-z0-9_-]{1,39}$/i', $value)) {
                    $this->telegram->sendMessage($context->chatId, 'shortener_provider_code must be a simple provider key such as gplinks.');
                    return;
                }
            } elseif (!preg_match('/^(?:-?[0-9]{5,}|@[A-Za-z][A-Za-z0-9_]{4,})$/', $value)) {
                $this->telegram->sendMessage($context->chatId,
                    'Use a numeric Telegram channel ID (usually starts with -100) or a public @channelusername.');
                return;
            }
            $this->settings->set($key, $value, $context->userId);
            $this->telegram->sendMessage($context->chatId, '✅ Saved ' . $key . '. Verify the bot has the required channel permissions.');
            return;
        }

        if ($command === '/start') {
            if ($argument !== '') {
                $this->collectionDelivery->open($context->chatId, $context->userId, $context->updateId, $argument);
                return;
            }
            $name = $update['message']['from']['first_name'] ?? 'there';
            $name = is_string($name) ? preg_replace('/[\x00-\x1F\x7F]/u', '', $name) : 'there';
            $name = trim(is_string($name) ? $name : 'there');
            if ($name === '') $name = 'there';
            $balance = $this->wallet->grantWelcomeReward($context->userId);
            $welcomeCoins = max(0, (int) $this->settings->get('welcome_coins', '0'));
            $this->telegram->sendMessage(
                $context->chatId,
                'Hey ' . $name . "! 👋\n\nWelcome to the File Store Bot.\nWallet balance: " . $balance . " coins."
                    . ($welcomeCoins > 0 ? "\nYour one-time welcome reward is handled automatically." : '')
                    . "\nUse /balance to check your wallet, or /help for commands."
            );
            return;
        }

        if ($command === '/balance' || $command === '/wallet') {
            $this->wallet->grantWelcomeReward($context->userId);
            $this->telegram->sendMessage($context->chatId, '🪙 Wallet balance: ' . $this->wallet->balance($context->userId) . ' coins.');
            return;
        }

        if ($command === '/help') {
            $this->telegram->sendMessage(
                $context->chatId,
                "Available commands:\n/start — Start the bot\n/balance — Check wallet balance\n/help — Show help\n/id — Show your Telegram user ID\n/post — Start an admin collection draft\n/done — Preview videos collected in the current draft\n/cancel — Cancel the current draft\n/skip — Skip optional thumbnail\n/setting set dump_channel_id CHANNEL_ID\n/setting set post_channel_id CHANNEL_ID\n/setting set force_subscribe_channel_id CHANNEL_ID\n/setting set collection_access_cost 0\n/setting set welcome_coins 10"
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
            $lines[] = 'Editable settings:';
            $lines[] = '/setting set dump_channel_id -1001234567890';
            $lines[] = '/setting set post_channel_id -1001234567890';
            $lines[] = '/setting set force_subscribe_channel_id @channelname';
            $lines[] = '/setting set collection_access_cost 0';
            $lines[] = '/setting set welcome_coins 10';
            $lines[] = '/setting set shortener_provider_code gplinks';
            $lines[] = '/setting set shortener_reward_coins 20';
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

    /** @param array<string, mixed> $update */
    private function callbackChatId(array $update, int $userId): int
    {
        $message = $update['callback_query']['message'] ?? null;
        $chatId = is_array($message) && is_array($message['chat'] ?? null)
            ? filter_var($message['chat']['id'] ?? null, FILTER_VALIDATE_INT) : false;
        return $chatId !== false && $chatId !== null ? (int) $chatId : $userId;
    }

    private function requireOwner(int $userId, int $chatId): bool
    {
        if ($this->admin->isOwner($userId)) return true;
        $this->telegram->sendMessage($chatId, '⛔ This command is available to the bot owner only.');
        return false;
    }
}
