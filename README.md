# PHP Telegram File Store Bot

PHP Telegram bot for shared cPanel hosting, designed around MySQL/MariaDB, Telegram webhooks and bounded cron workers. The current code includes webhook security, durable update processing, an admin collection wizard, collection publishing, and initial collection access/delivery.

## Current implementation

- Environment/private-file configuration, privacy-conscious JSON logging, webhook secret verification, and Telegram Bot API client using PHP cURL/TLS
- PDO MySQL/MariaDB connection factory, versioned migrations, user profiles, persistent settings and durable Telegram update outbox with retry backoff
- Owner-only /post wizard: optional thumbnail, title, multiple videos, automatic copying to dump channel, preview and explicit publishing
- Permanent deep links using random collection codes
- /start post_<code> collection lookup, configurable force-subscription check, active Premium bypass, coin-cost check, and sequential delivery from saved dump-channel messages
- Per-delivery job and per-video status tracking, retry of undelivered videos, and outgoing message references for future auto-delete support
- Owner settings commands for dump/post/force-subscription channel IDs and collection access cost
- CLI migration/webhook setup commands and cPanel-compatible outbox worker

This is still an incremental development build, not production-ready. The current delivery implementation sends videos sequentially to preserve order and tracks progress; media-album batching, the full wallet/coin earning workflow, Premium purchase administration, referrals, configurable auto-delete workers, and the full interactive Settings panel remain future work. Premium access is read from the subscription table and bypasses coin charges, but subscription management is not yet implemented. Get Coins and View Premium Plans are UI placeholders until their workflows are added.

## Requirements

- PHP 8.1 or later
- PHP cURL and PDO MySQL extensions
- MySQL/MariaDB database
- HTTPS endpoint for Telegram webhook
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
        'DB_HOST' => 'localhost',
        'DB_PORT' => '3306',
        'DB_NAME' => 'cpanelprefix_database',
        'DB_USER' => 'cpanelprefix_dbuser',
        'DB_PASSWORD' => 'use-a-long-unique-password',
        'DB_CHARSET' => 'utf8mb4',
    ];

Set BOT_CONFIG_FILE to the full path of this file. Keep it outside public_html where possible. Environment values override values from the private file. Do not paste credentials into tracked source files.

Telegram webhook secret tokens should be 1–256 characters and use only letters, digits, underscore, or hyphen. Use a long random value. Do not reuse the bot token as the webhook secret.

## Database setup

1. Create a MySQL/MariaDB database and database user in cPanel.
2. Grant that user privileges only on the bot database.
3. Set DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD in the private configuration.
4. Keep database/, src/, and bin/ outside the public web root where possible.
5. Run php bin/migrate.php from the hosting CLI.
6. Migrations create the core tables, settings, update outbox, collection drafts/files, published collections/files, wallet ledger/account tables, Premium subscription records, and delivery job/item/message tracking.
7. Configure the webhook only after the schema exists.

MySQL DDL may auto-commit. If a migration fails partway, inspect the database before retrying.

## Owner settings and link delivery

Configure channels (the bot must be an administrator in the dump and post channels):

- /setting set dump_channel_id -1001234567890
- /setting set post_channel_id -1001234567890
- /setting set force_subscribe_channel_id @yourchannel (optional; use a public username for a join button)
- /setting set collection_access_cost 0 (0 means free; positive values require an existing wallet balance)

For Premium access, an active row in premium_subscriptions grants access without coin deduction. The Premium purchase and administration workflow is not implemented yet. Wallet balances are currently schema-backed and must be credited through a trusted future wallet workflow; do not manually alter balances for a production bot without a corresponding ledger entry.

When a user opens a valid published collection link, the bot verifies required channel membership, checks Premium, and if needed checks/debits the configured coin cost transactionally. It copies saved messages in original sequence and records each successful delivery. If a delivery fails, the user can reopen the same link to retry undelivered items without being charged a second time for that incomplete job.

## cPanel deployment notes

1. Expose only the public directory as the website document root where your hosting layout permits, or carefully adjust the webhook bootstrap path.
2. Configure BOT_CONFIG_FILE to point to the private config file.
3. Ensure PHP cURL and PDO MySQL are enabled and the domain has a valid HTTPS certificate.
4. Run php bin/migrate.php, then php bin/set-webhook.php from CLI after configuration.
5. Schedule php bin/process-update-outbox.php through cPanel cron every minute. Use the correct PHP CLI path supplied by your host.
6. Test with a private bot and test channels before sharing links publicly.

Telegram's send methods do not provide a general idempotency key. If Telegram accepts a message but the network times out before the bot receives the response, a duplicate message remains possible. A database update failure after a successful Telegram send can also create a duplicate on retry.

## Development rules

- Complete and review one phase at a time.
- Use PDO prepared statements for all database queries.
- Keep secrets out of Git and redact them from logs.
- Premium bypasses coin charges; it is not a coin package.
- Premium purchases use manual UPI or cryptocurrency instructions and explicit admin approval; no payment gateway is integrated.
