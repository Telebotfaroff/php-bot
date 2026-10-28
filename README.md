# PHP Telegram File Store Bot

PHP Telegram bot for shared cPanel hosting, designed around MySQL/MariaDB, Telegram webhooks and bounded cron workers. The current foundation includes security, database persistence, owner authorization, a durable update outbox, and the initial collection posting wizard.

## Current foundation

- Environment/private-file configuration loader and privacy-conscious JSON logger
- Telegram webhook secret-token verification and Telegram Bot API client using PHP cURL/TLS
- PDO MySQL/MariaDB connection factory using prepared statements
- Versioned PHP migrations and schema for users, Telegram updates, and persistent bot settings
- Durable Telegram update ID deduplication, sender profile upserts, and a database-backed update outbox with retry backoff
- CLI outbox worker (`bin/process-update-outbox.php`) for bounded cPanel cron retries
- Owner-only authorization using configured BOT_OWNER_ID
- Basic /start, /help, /id and owner-only read-only /settings commands
- CLI-only database migration and webhook setup commands
- GitHub Actions PHP syntax checks, smoke tests, MySQL integration tests, and authorization/context tests

The bot is still in development. The `/post` wizard can create and publish collections with permanent deep-link codes, but opening those links does not yet deliver the videos; access checks and delivery are a later phase. Full settings editing, wallet, Premium, referrals and auto-delete are not implemented yet. Webhook updates are stored in a durable outbox before dispatch. Failed dispatches are retried with backoff by the webhook or CLI worker.

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
5. Run `php bin/migrate.php` from the hosting CLI.
6. The command creates `schema_migrations`, `users`, `telegram_updates`, `bot_settings`, and `telegram_update_jobs`. Re-running it skips migrations already recorded.
7. Configure the webhook only after the schema exists.

MySQL DDL may auto-commit. If a migration fails partway, inspect the database before retrying.

## cPanel deployment notes

1. Expose only the `public` directory as the website document root where your hosting layout permits, or carefully adjust the webhook bootstrap path.
2. Configure BOT_CONFIG_FILE to point to the private config file.
3. Ensure PHP cURL and PDO MySQL are enabled and the domain has a valid HTTPS certificate.
4. Run `php bin/migrate.php`, then `php bin/set-webhook.php` from CLI after configuration.
5. The webhook stores updates and handles the currently implemented basic commands; it is not ready for public file delivery until collection and access control are implemented. The outbox uses retry/backoff for failed dispatches; because Telegram's send methods do not provide a general idempotency key, a rare timeout after Telegram accepted a message can still lead to a duplicate reply on retry.

## Development rules

- Complete and review one phase at a time.
- Use PDO prepared statements for all database queries.
- Keep secrets out of Git and redact them from logs.
- Only an authorized admin can publish collections or approve manual Premium purchases.
- Premium bypasses coin charges; it is not a coin package.
- Premium purchases use manual UPI or cryptocurrency instructions and explicit admin approval; no payment gateway is integrated.
