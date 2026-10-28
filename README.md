# PHP Telegram File Store Bot

PHP Telegram bot for shared cPanel hosting, designed around MySQL/MariaDB, Telegram webhooks and bounded cron workers. Development is phased. Phase 1 runtime/security checks and Phase 2 database/update persistence are implemented; command handling is not yet implemented.

## Current foundation

- Environment/private-file configuration loader and privacy-conscious JSON logger
- Telegram webhook secret-token verification and Telegram Bot API client using PHP cURL/TLS
- PDO MySQL/MariaDB connection factory using prepared statements
- Versioned PHP migration runner and initial schema
- Durable Telegram update ID deduplication and basic sender profile upserts
- CLI-only database migration and webhook setup commands
- GitHub Actions PHP syntax checks, Phase 1 smoke tests and Phase 2 MySQL integration tests

The webhook now requires a configured database and applied migrations. It stores valid Telegram updates once and records sender details when available. It still does not respond to /start or implement /post, collections, wallet, Premium, referrals or admin settings.

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
4. Upload the project with database/, src/, and bin/ available outside the public web root where possible.
5. Run the migration command from the hosting CLI: php bin/migrate.php.
6. The command creates schema_migrations, users, and telegram_updates. Re-running it safely skips migrations already recorded.
7. Only after the schema is created should the webhook be configured.

Migration note: MySQL DDL can auto-commit. The runner uses additive/idempotent initial table creation; inspect the database after a failed migration before retrying.

## cPanel deployment notes

1. Expose only the public directory as the website document root where your hosting layout permits, or adjust the webhook bootstrap path carefully if cPanel requires another layout.
2. Configure BOT_CONFIG_FILE to point to the private config file.
3. Ensure PHP cURL and PDO MySQL are enabled and the domain has a valid HTTPS certificate.
4. Run php bin/migrate.php and then php bin/set-webhook.php from CLI after configuration.
5. Do not configure the production webhook until the database schema exists and the endpoint is reachable over HTTPS.
6. The webhook currently persists updates but does not handle commands; the bot is not ready for public use yet.

## Development rules

- Complete and review one phase at a time.
- Use PDO prepared statements for all database queries.
- Keep secrets out of Git and redact them from logs.
- Only an authorized admin can publish collections or approve manual Premium purchases.
- Premium bypasses coin charges; it is not a coin package.
- Premium purchases use manual UPI or cryptocurrency instructions and explicit admin approval; no payment gateway is integrated.
