# PHP Telegram File Store Bot

PHP Telegram bot for shared cPanel hosting, designed around MySQL/MariaDB, Telegram webhooks and bounded cron workers. Development is phased. This repository currently contains the Phase 1 runtime/security foundation.

## Phase 1 contents

- Environment/private-file configuration loader
- Privacy-conscious JSON logger
- Telegram webhook secret-token verification
- Telegram Bot API client using PHP cURL and TLS verification
- Webhook endpoint that validates and acknowledges Telegram updates
- CLI-only webhook setup command

Important: the webhook currently acknowledges valid updates but does not process bot commands. Database-backed deduplication, command handlers, /post, collections, wallet, Premium, referrals and admin settings are not implemented yet.

## Requirements

- PHP 8.1 or later recommended
- PHP cURL extension
- HTTPS endpoint for Telegram webhook
- MySQL/MariaDB required in the next phase
- No Redis, MongoDB, Docker, or continuously running daemon

## Configuration

Never commit real credentials. Configure values through the hosting environment or a private PHP config file stored outside the public web root.

Example private configuration file:

    <?php
    return [
        'BOT_TOKEN' => 'replace-with-real-token',
        'BOT_USERNAME' => 'your_bot_username_without_at',
        'BOT_OWNER_ID' => 'your_numeric_telegram_user_id',
        'WEBHOOK_SECRET' => 'replace-with-a-random-secret-token',
        'WEBHOOK_URL' => 'https://example.com/webhook.php',
        'APP_ENV' => 'production',
        'LOG_FILE' => '/home/yourcpaneluser/private/php-bot.log',
    ];

Set the environment variable BOT_CONFIG_FILE to the full path of this file. Keep it outside public_html where possible. If your host cannot configure environment variables, ask the host how to set them securely; do not paste credentials into tracked source files.

Telegram webhook secret tokens should be 1–256 characters and use only letters, digits, underscore, or hyphen. Use a long random value. Do not reuse the bot token as the webhook secret.

## cPanel deployment notes

1. Upload the project so source code is outside the public web root where your hosting layout permits.
2. Expose only the public directory as the website document root, or copy its entrypoint to the domain document root and adjust the bootstrap path carefully.
3. Configure BOT_CONFIG_FILE to point to a private configuration file.
4. Ensure PHP cURL is enabled and the domain has a valid HTTPS certificate.
5. Run php bin/set-webhook.php from CLI after configuration. This command refuses web requests.
6. Do not configure the production webhook until the endpoint is reachable over HTTPS and the secrets are set.
7. The current endpoint does not run commands yet; wait until the update router and database phases are implemented before using the bot.

## Development rules

- Complete and review one phase at a time.
- Use PDO prepared statements for all database queries.
- Keep secrets out of Git and redact them from logs.
- Only an authorized admin can publish collections or approve manual Premium purchases.
- Premium bypasses coin charges; it is not a coin package.
- Premium purchases use manual UPI or cryptocurrency instructions and explicit admin approval; no payment gateway is integrated.
