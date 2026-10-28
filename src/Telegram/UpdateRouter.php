<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Config\Config;
use App\Database\SettingsRepository;
use App\Security\AdminAuthorizer;
use App\Services\CollectionDeliveryService;
use App\Services\PostWizardService;
use App\Services\PremiumService;
use App\Services\ReferralService;
use App\Services\WalletService;

final class UpdateRouter
{
    public function __construct(
        private readonly BotApiClient $telegram,
        private readonly SettingsRepository $settings,
        private readonly AdminAuthorizer $admin,
        private readonly PostWizardService $postWizard,
        private readonly CollectionDeliveryService $collectionDelivery,
        private readonly WalletService $wallet,
        private readonly PremiumService $premium,
        private readonly ReferralService $referrals
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
                if ($context->callbackId !== null) $this->telegram->answerCallbackQuery($context->callbackId);
                if ($context->userId !== null) $this->sendPremiumPlans($this->callbackChatId($update, $context->userId));
                return;
            }
            if ($context->callbackData !== null && preg_match('/^premium:plan:([1-9][0-9]{0,15})$/', $context->callbackData, $match)) {
                if ($context->callbackId !== null) $this->telegram->answerCallbackQuery($context->callbackId);
                $chatId = $this->callbackChatId($update, (int) $context->userId);
                $planId = (int) $match[1];
                $this->telegram->sendMessage($chatId, 'Choose a manual payment method:', [
                    'reply_markup' => ['inline_keyboard' => [
                        [
                            ['text' => 'UPI', 'callback_data' => 'premium:method:' . $planId . ':upi'],
                            ['text' => 'Crypto', 'callback_data' => 'premium:method:' . $planId . ':crypto'],
                        ],
                    ]],
                ]);
                return;
            }
            if ($context->callbackData !== null && preg_match('/^premium:method:([1-9][0-9]{0,15}):(upi|crypto)$/', $context->callbackData, $match)) {
                if ($context->callbackId !== null) $this->telegram->answerCallbackQuery($context->callbackId);
                $chatId = $this->callbackChatId($update, (int) $context->userId);
                try {
                    $requestId = $this->premium->createRequest((int) $context->userId, (int) $match[1], $match[2]);
                    $settingKey = $match[2] === 'upi' ? 'premium_upi_instructions' : 'premium_crypto_instructions';
                    $instructions = trim((string) $this->settings->get($settingKey, ''));
                    if ($instructions === '') $instructions = 'Payment instructions have not been configured. Contact the bot owner before paying.';
                    $this->telegram->sendMessage($chatId,
                        "⭐ Premium payment request #{$requestId} created (pending admin review).\n"
                        . "Method: " . strtoupper($match[2]) . "\n\n" . $instructions
                        . "\n\nAfter paying, submit the transaction reference with:\n/payref {$requestId} YOUR_REFERENCE"
                        . "\nPremium activates only after the admin verifies and approves your request.");
                } catch (\Throwable $e) {
                    $this->telegram->sendMessage($chatId, $e->getMessage());
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

        if (($command === '/setting' || $command === '/settings') && preg_match('/^set\s+(dump_channel_id|post_channel_id|force_subscribe_channel_id|collection_access_cost|welcome_coins|shortener_reward_coins|shortener_provider_code|premium_upi_instructions|premium_crypto_instructions)\s+(.+)$/i', $argument, $match)) {
            if (!$this->requireOwner($context->userId, $context->chatId)) return;
            $key = strtolower($match[1]);
            $value = trim($match[2]);
            if (in_array($key, ['collection_access_cost', 'welcome_coins', 'shortener_reward_coins'], true)) {
                if (!preg_match('/^(0|[1-9][0-9]{0,8})$/', $value)) {
                    $this->telegram->sendMessage($context->chatId, $key . ' must be a whole number from 0 to 999999999.');
                    return;
                }
            } elseif (in_array($key, ['premium_upi_instructions', 'premium_crypto_instructions'], true)) {
                if ($value === '' || strlen($value) > 5000) {
                    $this->telegram->sendMessage($context->chatId, $key . ' must be 1–5000 characters.');
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

        if ($command === '/premium') {
            $this->sendPremiumPlans($context->chatId);
            return;
        }

        if ($command === '/payref') {
            if (!preg_match('/^([1-9][0-9]{0,15})\\s+(.+)$/s', $argument, $match)) {
                $this->telegram->sendMessage($context->chatId, 'Usage: /payref REQUEST_ID TRANSACTION_REFERENCE');
                return;
            }
            try {
                $saved = $this->premium->submitReference($context->userId, (int) $match[1], $match[2]);
                $this->telegram->sendMessage($context->chatId, $saved
                    ? '✅ Reference saved. Your Premium request is still pending; only admin approval activates Premium.'
                    : 'Request not found, already reviewed, or it does not belong to your account.');
            } catch (\Throwable $e) {
                $this->telegram->sendMessage($context->chatId, $e->getMessage());
            }
            return;
        }

        if ($command === '/premium_plan_add') {
            if (!$this->requireOwner($context->userId, $context->chatId)) return;
            $fields = array_map('trim', explode('|', $argument));
            if (count($fields) !== 4 || !ctype_digit($fields[1])) {
                $this->telegram->sendMessage($context->chatId, 'Usage: /premium_plan_add NAME|DAYS|PRICE|CURRENCY');
                return;
            }
            try {
                $id = $this->premium->createPlan($fields[0], (int) $fields[1], $fields[2], $fields[3]);
                $this->telegram->sendMessage($context->chatId, '✅ Premium plan #' . $id . ' created.');
            } catch (\Throwable $e) {
                $this->telegram->sendMessage($context->chatId, $e->getMessage());
            }
            return;
        }

        if ($command === '/premium_plan_disable') {
            if (!$this->requireOwner($context->userId, $context->chatId)) return;
            if (!ctype_digit($argument) || (int) $argument < 1) {
                $this->telegram->sendMessage($context->chatId, 'Usage: /premium_plan_disable PLAN_ID');
                return;
            }
            $this->telegram->sendMessage($context->chatId,
                $this->premium->disablePlan((int) $argument) ? 'Plan disabled for future purchases.' : 'No enabled plan found with that ID.');
            return;
        }

        if ($command === '/premium_pending') {
            if (!$this->requireOwner($context->userId, $context->chatId)) return;
            $pending = $this->premium->pendingRequests();
            if ($pending === []) {
                $this->telegram->sendMessage($context->chatId, 'No pending Premium payment requests.');
                return;
            }
            $lines = ['Pending Premium requests:'];
            foreach ($pending as $request) {
                $lines[] = '#' . $request['id'] . ' | user ' . $request['telegram_user_id'] . ' | '
                    . $request['plan_name_snapshot'] . ' (' . $request['duration_days_snapshot'] . ' days) | '
                    . $request['price_amount_snapshot'] . ' ' . $request['currency_snapshot'] . ' | '
                    . strtoupper((string) $request['payment_method']) . ' | reference: '
                    . ((string) ($request['transaction_reference'] ?? '') ?: '[not submitted]');
            }
            $lines[] = '';
            $lines[] = '/premium_approve REQUEST_ID';
            $lines[] = '/premium_reject REQUEST_ID';
            $this->telegram->sendMessage($context->chatId, implode("\n", $lines));
            return;
        }

        if ($command === '/premium_approve' || $command === '/premium_reject') {
            if (!$this->requireOwner($context->userId, $context->chatId)) return;
            if (!ctype_digit($argument) || (int) $argument < 1) {
                $this->telegram->sendMessage($context->chatId, 'Usage: ' . $command . ' REQUEST_ID');
                return;
            }
            try {
                $done = $this->premium->decide((int) $argument, $context->userId, $command === '/premium_approve');
                $this->telegram->sendMessage($context->chatId, $done
                    ? ($command === '/premium_approve' ? '✅ Request approved and Premium activated/extended.' : 'Request rejected.')
                    : 'Request not found or already reviewed.');
            } catch (\Throwable $e) {
                $this->telegram->sendMessage($context->chatId, $e->getMessage());
            }
            return;
        }

        if ($command === '/start') {
            $referrerId = null;
            if (preg_match('/^ref_([1-9][0-9]{0,15})$/', $argument, $refMatch)) {
                $referrerId = (int) $refMatch[1];
            }
            $referralResult = $this->referrals->processStart($context->userId, $referrerId);
            if ($argument !== '' && $referrerId === null) {
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
                    . ($referralResult['rewarded'] ? "\n🎉 Your referral was successful! The referrer received 24 hours of Premium." : '')
                    . "\nUse /referral to invite friends, /balance to check your wallet, or /help for commands."
            );
            return;
        }

        if ($command === '/referral') {
            $username = trim((string) Config::get('BOT_USERNAME', ''));
            if ($username === '') {
                $this->telegram->sendMessage($context->chatId, 'Referral links are unavailable until BOT_USERNAME is configured in the private server config.');
                return;
            }
            $stats = $this->referrals->stats($context->userId);
            $link = 'https://t.me/' . ltrim($username, '@') . '?start=ref_' . $context->userId;
            $this->telegram->sendMessage($context->chatId,
                "🎁 Invite friends and earn 24 hours of Premium for each new user who starts the bot with your link.\n\n"
                . "Your referral link:\n" . $link . "\n\n"
                . "Qualified referrals: " . $stats['qualified'] . "\nPremium rewards issued: " . $stats['rewards']);
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
                "Available commands:\n/start — Start the bot\n/referral — Get your referral link and stats\n/balance — Check wallet balance\n/help — Show help\n/id — Show your Telegram user ID\n/post — Start an admin collection draft\n/done — Preview videos collected in the current draft\n/cancel — Cancel the current draft\n/skip — Skip optional thumbnail\n/setting set dump_channel_id CHANNEL_ID\n/setting set post_channel_id CHANNEL_ID\n/setting set force_subscribe_channel_id CHANNEL_ID\n/setting set collection_access_cost 0\n/setting set welcome_coins 10"
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
            $lines[] = '/setting set premium_upi_instructions YOUR_UPI_ID_AND_PAYMENT_STEPS';
            $lines[] = '/setting set premium_crypto_instructions NETWORK_AND_WALLET_ADDRESS';
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


    private function sendPremiumPlans(int $chatId): void
    {
        $plans = $this->premium->plans();
        if ($plans === []) {
            $this->telegram->sendMessage($chatId, 'No Premium plans are available yet. Please check back later.');
            return;
        }
        $keyboard = [];
        foreach ($plans as $plan) {
            $keyboard[] = [[
                'text' => (string) $plan['name'] . ' — ' . (int) $plan['duration_days'] . ' days — '
                    . $plan['price_amount'] . ' ' . $plan['currency'],
                'callback_data' => 'premium:plan:' . (int) $plan['id'],
            ]];
        }
        $this->telegram->sendMessage($chatId, '⭐ Premium plans\n\nChoose a plan. Payments are manual; an admin must verify and approve them.', [
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ]);
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
