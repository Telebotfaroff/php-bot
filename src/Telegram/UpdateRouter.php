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
            if ($context->callbackData !== null && str_starts_with($context->callbackData, 'admin:')) {
                $this->handleAdminCallback($update, $context);
                return;
            }
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

        $promoPendingKey = 'admin_pending_promo_create_' . $context->userId;
        if ((string) $this->settings->get($promoPendingKey, '') === '1' && $context->text !== null && !str_starts_with(trim($context->text), '/')) {
            if (!$this->admin->isOwner($context->userId)) {
                $this->settings->set($promoPendingKey, '');
                $this->telegram->sendMessage($context->chatId, '⛔ This admin action is no longer available.');
                return;
            }
            $fields = preg_split('/\s+/', trim($context->text));
            if (!is_array($fields) || count($fields) !== 4) {
                $this->telegram->sendMessage($context->chatId, "Format: CODE COINS MAX_USERS EXPIRY_DAYS\nExample: SUMMER50 50 100 2\nSend /cancel to cancel.");
                return;
            }
            try {
                $promo = $this->wallet->createPromoCode((string) $fields[0], (int) $fields[1], (int) $fields[2], (int) $fields[3], $context->userId);
                $this->settings->set($promoPendingKey, '');
                $this->telegram->sendMessage($context->chatId,
                    "✅ Promo code created!\n\nCode: " . $promo['code'] . "\nReward: " . $promo['reward'] . " coins\nMaximum users: " . $promo['max_uses']
                    . "\nExpires: " . $promo['expires_at'] . " UTC\n\nUsers can send the code as a normal message to redeem it.");
            } catch (\Throwable $exception) {
                $this->telegram->sendMessage($context->chatId, 'Could not create promo code: ' . $exception->getMessage());
            }
            return;
        }
        if ((string) $this->settings->get($promoPendingKey, '') === '1' && in_array($command, ['/cancel', '/setting', '/settings'], true)) {
            $this->settings->set($promoPendingKey, '');
            if ($command === '/cancel') {
                $this->telegram->sendMessage($context->chatId, 'Promo creation cancelled.');
                return;
            }
        }

        // Complete a setting edit started from the inline admin menu.
        $pendingKey = 'admin_pending_' . $context->userId;
        $pendingSetting = (string) $this->settings->get($pendingKey, '');
        if ($pendingSetting !== '' && $context->text !== null && !str_starts_with(trim($context->text), '/')) {
            if (!$this->admin->isOwner($context->userId)) {
                $this->settings->set($pendingKey, '');
                $this->telegram->sendMessage($context->chatId, '⛔ This admin action is no longer available.');
                return;
            }
            try {
                $value = $this->validateAdminSettingValue($pendingSetting, trim($context->text));
                if ($pendingSetting === 'force_subscribe_channel_id' && $value === '0') $value = '';
                $this->settings->set($pendingSetting, $value, $context->userId);
                $this->settings->set($pendingKey, '');
                $this->telegram->sendMessage($context->chatId, '✅ Saved ' . $this->adminSettingLabel($pendingSetting) . '.');
            } catch (\InvalidArgumentException $exception) {
                $this->telegram->sendMessage($context->chatId, $exception->getMessage() . "\nSend the value again, or use /cancel to leave edit mode.");
            }
            return;
        }
        if ($pendingSetting !== '' && in_array($command, ['/cancel', '/setting', '/settings'], true)) {
            $this->settings->set($pendingKey, '');
            if ($command === '/cancel') {
                $this->telegram->sendMessage($context->chatId, 'Setting edit cancelled.');
                return;
            }
        }

        if ($command === '/post') {
            if (!$this->requireOwner($context->userId, $context->chatId)) return;
            $this->postWizard->begin($context->userId, $context->chatId);
            return;
        }

        if (($command === '/setting' || $command === '/settings') && preg_match('/^set\s+(dump_channel_id|post_channel_id|force_subscribe_channel_id|collection_access_cost|welcome_coins|shortener_reward_coins|shortener_provider_code|premium_upi_instructions|premium_crypto_instructions|auto_delete_enabled|auto_delete_after_seconds)\s+(.+)$/i', $argument, $match)) {
            if (!$this->requireOwner($context->userId, $context->chatId)) return;
            $key = strtolower($match[1]);
            $value = trim($match[2]);
            if ($key === 'auto_delete_enabled') {
                if (!in_array(strtolower($value), ['0', '1', 'true', 'false', 'on', 'off'], true)) {
                    $this->telegram->sendMessage($context->chatId, 'auto_delete_enabled must be true or false.');
                    return;
                }
                $value = in_array(strtolower($value), ['1', 'true', 'on'], true) ? '1' : '0';
            } elseif ($key === 'auto_delete_after_seconds') {
                if (!ctype_digit($value) || (int) $value < 60 || (int) $value > 31536000) {
                    $this->telegram->sendMessage($context->chatId, 'auto_delete_after_seconds must be between 60 and 31536000.');
                    return;
                }
            } elseif (in_array($key, ['collection_access_cost', 'welcome_coins', 'shortener_reward_coins'], true)) {
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
            if (!preg_match('/^([1-9][0-9]{0,15})\s+(.+)$/s', $argument, $match)) {
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
                "Available commands:\n/start — Start the bot\n/referral — Get your referral link and stats\n/balance — Check your wallet\n/help — Show help\n/id — Show your Telegram user ID\n/post — Start an admin collection draft\n/done — Preview videos collected in the current draft\n/cancel — Cancel the current action or draft\n/skip — Skip optional thumbnail\n/setting — Open the owner-only Admin Panel"
            );
            return;
        }

        if ($command === '/settings' || $command === '/setting') {
            if (!$this->requireOwner($context->userId, $context->chatId)) return;
            $this->sendAdminMenu($context->chatId);
            return;
        }

        if ($command === '/id') {
            $this->telegram->sendMessage($context->chatId, 'Your Telegram user ID: ' . $context->userId);
            return;
        }

        // A plain text promo code is checked before handing free text to the collection wizard.
        if ($context->text !== null && preg_match('/^[A-Za-z0-9_-]{3,32}$/', trim($context->text))) {
            $result = $this->wallet->redeemPromoCode($context->userId, trim($context->text));
            if ($result['status'] === 'redeemed') {
                $this->telegram->sendMessage($context->chatId, "🎉 Promo code accepted! You received " . $result['coins'] . " coins.\nWallet balance: " . $result['balance'] . " coins.");
                return;
            }
            if ($result['status'] === 'already_used') {
                $this->telegram->sendMessage($context->chatId, 'You have already redeemed this promo code.');
                return;
            }
            if ($result['status'] === 'expired') {
                $this->telegram->sendMessage($context->chatId, 'This promo code has expired or is disabled.');
                return;
            }
            if ($result['status'] === 'exhausted') {
                $this->telegram->sendMessage($context->chatId, 'Sorry, this promo code has reached its user limit.');
                return;
            }
        }

        // Wizard messages are handled last so /help and /settings remain usable mid-draft.
        $this->postWizard->handleMessage($update, $context->userId, $context->chatId, $context->text);
    }

 
    /** @param array<string, mixed> $update */
    private function handleAdminCallback(array $update, UpdateContext $context): void
    {
        $chatId = $this->callbackChatId($update, $context->userId ?? 0);
        if ($context->userId === null || !$this->admin->isOwner($context->userId)) {
            if ($context->callbackId !== null) $this->telegram->answerCallbackQuery($context->callbackId, ['text' => 'Owner only.']);
            return;
        }
        if ($context->callbackId !== null) $this->telegram->answerCallbackQuery($context->callbackId);

        $data = (string) $context->callbackData;
        if ($data === 'admin:menu' || $data === 'admin:home') {
            $this->sendAdminMenu($chatId);
            return;
        }
        if ($data === 'admin:cancel') {
            $this->settings->set('admin_pending_' . $context->userId, '');
            $this->telegram->sendMessage($chatId, 'Setting edit cancelled.');
            $this->sendAdminMenu($chatId);
            return;
        }
        if ($data === 'admin:promo:create') {
            $this->settings->set('admin_pending_promo_create_' . $context->userId, '1');
            $this->telegram->sendMessage($chatId, "🎟 Create a promo code\n\nSend four values separated by spaces:\nCODE COINS MAX_USERS EXPIRY_DAYS\n\nExample: SUMMER50 50 100 2\n\nThis creates SUMMER50 for 50 coins, up to 100 users, expiring in 2 days.\nUse /cancel to cancel.");
            return;
        }
        if ($data === 'admin:promo:list') {
            $promos = $this->wallet->recentPromoCodes(10);
            if ($promos === []) {
                $this->telegram->sendMessage($chatId, 'No promo codes have been created yet.');
                return;
            }
            $lines = ['🎟 Recent promo codes', ''];
            foreach ($promos as $promo) {
                $status = !(bool) $promo['is_active'] ? 'disabled' : (strtotime($promo['expires_at']) <= time() ? 'expired' : ((int) $promo['redeemed_count'] >= (int) $promo['max_uses'] ? 'fully redeemed' : 'active'));
                $lines[] = $promo['code'] . ' — ' . (int) $promo['reward_coins'] . ' coins — ' . (int) $promo['redeemed_count'] . '/' . (int) $promo['max_uses'] . ' users — ' . $status . ' — expires ' . $promo['expires_at'] . ' UTC';
            }
            $this->telegram->sendMessage($chatId, implode("\n", $lines));
            return;
        }
        if (preg_match('/^admin:cat:(channels|access|wallet|shortener|premium|cleanup|promos)$/', $data, $match)) {
            $this->sendAdminCategory($chatId, $match[1]);
            return;
        }
        if (preg_match('/^admin:toggle:(auto_delete_enabled)$/', $data, $match)) {
            $key = $match[1];
            $current = strtolower((string) $this->settings->get($key, 'false'));
            $next = in_array($current, ['1', 'true', 'on'], true) ? '0' : '1';
            $this->settings->set($key, $next, $context->userId);
            $this->sendAdminCategory($chatId, 'cleanup');
            return;
        }
        if (preg_match('/^admin:edit:([a-z][a-z0-9_]{1,60})$/', $data, $match)) {
            $key = $match[1];
            $allowed = array_column($this->adminSettingDefinitions(), 'key');
            if (!in_array($key, $allowed, true)) {
                $this->telegram->sendMessage($chatId, 'Unknown setting. Open /setting and try again.');
                return;
            }
            $this->settings->set('admin_pending_' . $context->userId, $key);
            $hint = match ($key) {
                'dump_channel_id', 'post_channel_id' => 'Send the channel ID (usually starts with -100), or a public @channelusername.',
                'force_subscribe_channel_id' => 'Send a channel ID such as -1001234567890 or a public @channelusername. Send 0 to disable.',
                'collection_access_cost', 'welcome_coins', 'shortener_reward_coins' => 'Send a whole number (0 or greater).',
                'shortener_provider_code' => 'Send a provider key such as gplinks (letters, numbers, _ and - only).',
                'auto_delete_after_seconds' => 'Send the delay in seconds (minimum 60, maximum 31536000).',
                'premium_upi_instructions' => 'Send the UPI payment instructions users should see.',
                'premium_crypto_instructions' => 'Send the crypto network and wallet instructions users should see.',
                default => 'Send the new value.',
            };
            $this->telegram->sendMessage($chatId, '✏️ Edit ' . $this->adminSettingLabel($key) . "\n\n" . $hint . "\n\nSend /cancel to cancel.");
            return;
        }
        $this->sendAdminMenu($chatId);
    }

    private function sendAdminMenu(int $chatId): void
    {
        $keyboard = [
            [['text' => '📢 Channels', 'callback_data' => 'admin:cat:channels'], ['text' => '🔐 Access', 'callback_data' => 'admin:cat:access']],
            [['text' => '🪙 Wallet & Rewards', 'callback_data' => 'admin:cat:wallet'], ['text' => '🔗 Shortener', 'callback_data' => 'admin:cat:shortener']],
            [['text' => '⭐ Premium & Payments', 'callback_data' => 'admin:cat:premium'], ['text' => '🗑 Auto-delete', 'callback_data' => 'admin:cat:cleanup']],
            [['text' => '🎟 Promo Codes', 'callback_data' => 'admin:cat:promos']],
        ];
        $this->telegram->sendMessage($chatId, "⚙️ File Store Admin Panel\n\nChoose a section below. Tap a setting to edit it; auto-delete can be toggled with one tap.", [
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ]);
    }

    private function sendAdminCategory(int $chatId, string $category): void
    {
        if ($category === 'promos') {
            $this->telegram->sendMessage($chatId, '🎟 Promo Codes\n\nCreate codes that reward wallet coins, limit the number of users, and expire automatically.', [
                'reply_markup' => ['inline_keyboard' => [
                    [['text' => '➕ Create promo code', 'callback_data' => 'admin:promo:create']],
                    [['text' => '📋 Recent promo codes', 'callback_data' => 'admin:promo:list']],
                    [['text' => '⬅️ Back to Admin Panel', 'callback_data' => 'admin:menu']],
                ]],
            ]);
            return;
        }
        $definitions = $this->adminSettingDefinitions();
        $keyboard = [];
        foreach ($definitions as $definition) {
            if ($definition['category'] !== $category) continue;
            $key = $definition['key'];
            $value = (string) $this->settings->get($key, '');
            if ($key === 'auto_delete_enabled') {
                $enabled = in_array(strtolower($value), ['1', 'true', 'on'], true);
                $keyboard[] = [['text' => ($enabled ? '🟢 Auto-delete: ON' : '⚪ Auto-delete: OFF'), 'callback_data' => 'admin:toggle:auto_delete_enabled']];
                continue;
            }
            $display = $value === '' ? 'Not set' : (function_exists('mb_substr') ? mb_substr($value, 0, 24) : substr($value, 0, 24));
            if (preg_match('/upi|crypto|password|token|secret/i', $key)) $display = $value === '' ? 'Not set' : 'Configured';
            $keyboard[] = [['text' => $definition['label'] . ': ' . $display, 'callback_data' => 'admin:edit:' . $key]];
        }
        $keyboard[] = [['text' => '⬅️ Back to Admin Panel', 'callback_data' => 'admin:menu']];
        $title = match ($category) {
            'channels' => '📢 Channel Settings',
            'access' => '🔐 Access Control',
            'wallet' => '🪙 Wallet & Rewards',
            'shortener' => '🔗 Shortener Settings',
            'premium' => '⭐ Premium & Payments',
            'cleanup' => '🗑 Auto-delete Settings',
            default => '⚙️ Settings',
        };
        $this->telegram->sendMessage($chatId, $title . "\n\nTap an item to edit it.", [
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ]);
    }

    /** @return list<array{key:string,label:string,category:string}> */
    private function adminSettingDefinitions(): array
    {
        return [
            ['key' => 'dump_channel_id', 'label' => 'Dump channel', 'category' => 'channels'],
            ['key' => 'post_channel_id', 'label' => 'Post channel', 'category' => 'channels'],
            ['key' => 'force_subscribe_channel_id', 'label' => 'Force-subscribe channel', 'category' => 'channels'],
            ['key' => 'collection_access_cost', 'label' => 'Collection access cost', 'category' => 'access'],
            ['key' => 'welcome_coins', 'label' => 'Welcome coins', 'category' => 'wallet'],
            ['key' => 'shortener_reward_coins', 'label' => 'Shortener reward coins', 'category' => 'wallet'],
            ['key' => 'shortener_provider_code', 'label' => 'Shortener provider', 'category' => 'shortener'],
            ['key' => 'premium_upi_instructions', 'label' => 'UPI instructions', 'category' => 'premium'],
            ['key' => 'premium_crypto_instructions', 'label' => 'Crypto instructions', 'category' => 'premium'],
            ['key' => 'auto_delete_enabled', 'label' => 'Auto-delete', 'category' => 'cleanup'],
            ['key' => 'auto_delete_after_seconds', 'label' => 'Auto-delete delay (seconds)', 'category' => 'cleanup'],
        ];
    }

    private function adminSettingLabel(string $key): string
    {
        foreach ($this->adminSettingDefinitions() as $definition) {
            if ($definition['key'] === $key) return $definition['label'];
        }
        return $key;
    }

    private function validateAdminSettingValue(string $key, string $value): string
    {
        if ($value === '') throw new \InvalidArgumentException('Value cannot be empty.');
        if (in_array($key, ['dump_channel_id', 'post_channel_id', 'force_subscribe_channel_id'], true)) {
            if ($key === 'force_subscribe_channel_id' && $value === '0') return '0';
            if (!preg_match('/^(?:-?[0-9]{5,}|@[A-Za-z][A-Za-z0-9_]{4,})$/', $value)) {
                throw new \InvalidArgumentException('Use a numeric channel ID (usually -100...) or a public @channelusername.');
            }
            return $value;
        }
        if (in_array($key, ['collection_access_cost', 'welcome_coins', 'shortener_reward_coins'], true)) {
            if (!preg_match('/^(0|[1-9][0-9]{0,8})$/', $value)) throw new \InvalidArgumentException('Enter a whole number from 0 to 999999999.');
            return $value;
        }
        if ($key === 'shortener_provider_code') {
            if (!preg_match('/^[a-z][a-z0-9_-]{1,39}$/i', $value)) throw new \InvalidArgumentException('Use a provider key such as gplinks (letters, numbers, _ and - only).');
            return strtolower($value);
        }
        if ($key === 'auto_delete_after_seconds') {
            if (!ctype_digit($value) || (int) $value < 60 || (int) $value > 31536000) throw new \InvalidArgumentException('Enter a delay between 60 and 31536000 seconds.');
            return $value;
        }
        if (in_array($key, ['premium_upi_instructions', 'premium_crypto_instructions'], true)) {
            if (strlen($value) > 5000) throw new \InvalidArgumentException('Instructions must be at most 5000 characters.');
            return $value;
        }
        throw new \InvalidArgumentException('That setting cannot be edited from this menu.');
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
