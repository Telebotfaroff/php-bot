# Deploying on Koyeb

This repository includes a Docker deployment for Koyeb. It serves the Telegram webhook with Apache/PHP and runs the durable update-outbox worker and auto-delete worker as supervised processes in the same service.

## Before you start

You need:
- A Koyeb account and access to deploy a service from this GitHub repository.
- An external MySQL 8 or compatible MariaDB database reachable from Koyeb. The current schema uses MySQL-specific migrations; PostgreSQL is not supported by this branch.
- A Telegram bot token from BotFather and the numeric Telegram user ID of the owner.

The Koyeb service does not include a database. Use a MySQL provider that permits connections from your Koyeb service, and configure any required network allowlist/TLS options.

## Create the service

1. Push or select the `deploy/koyeb` branch in GitHub.
2. In Koyeb, create a new App and deploy from GitHub, selecting this repository and the `deploy/koyeb` branch.
3. Choose **Docker** as the builder. Koyeb should detect the repository's `Dockerfile`.
4. Configure the exposed service port as **8080** and the protocol as HTTP. Koyeb terminates public HTTPS and forwards traffic to the container.
5. Configure a health check using HTTP path `/health.php` on port `8080`.
6. Add the environment variables below before deploying.

## Environment variables

Set these in the Koyeb service's environment/secrets settings. Never commit real values to Git.

| Variable | Required | Value |
|---|---|---|
| `BOT_TOKEN` | Yes | BotFather token |
| `BOT_USERNAME` | Yes | Username without `@` |
| `BOT_OWNER_ID` | Yes | Numeric Telegram owner ID |
| `WEBHOOK_SECRET` | Yes | Random 32–256 character value using letters, digits, `_` or `-` |
| `WEBHOOK_URL` | Yes | `https://YOUR-KOYEB-DOMAIN/webhook.php` |
| `APP_ENV` | Recommended | `production` |
| `DB_HOST` | Yes | External database hostname |
| `DB_PORT` | Yes | Database port, usually `3306` |
| `DB_NAME` | Yes | Database name |
| `DB_USER` | Yes | Database username |
| `DB_PASSWORD` | Yes | Database password |
| `DB_CHARSET` | Recommended | `utf8mb4` |
| `DB_SSL_MODE` | Optional | Set to `required` for TLS-enabled providers such as Aiven |
| `DB_SSL_CA` | Optional | Path to provider CA certificate inside the container; enables certificate verification |
| `AUTO_MIGRATE` | Optional | Defaults to `true`; runs pending migrations before Apache/workers start |
| `LOG_FILE` | Optional | `/dev/stderr` or a writable path; platform logs are preferred |

When `DB_SSL_MODE=required`, TLS is enabled. For full server identity verification, also provide the provider CA certificate through `DB_SSL_CA`. Without a CA file, TLS encryption is requested but certificate identity verification is disabled. Do not set `BOT_CONFIG_FILE` on Koyeb unless you deliberately mount a persistent secret file. Environment variables take precedence over private-file values.

## Automatic database migrations

On container startup, `docker/entrypoint.sh` runs `php bin/migrate.php` before starting Apache and the background workers. The migration runner records applied migration files and skips them on subsequent restarts. If the database is unreachable or a migration fails, the container exits rather than serving an app with an uninitialized schema. Keep the service at one instance while using the built-in workers and automatic migrations; do not run multiple instances concurrently during startup.

To disable this behavior, set `AUTO_MIGRATE=false` and run migrations manually using `php bin/migrate.php` from a PHP CLI environment with the same environment variables.

## Register the Telegram webhook

After the Koyeb service is deployed and its HTTPS domain is assigned, set `WEBHOOK_URL` to the exact public URL ending in `/webhook.php`. Then run this command once in a PHP CLI environment with the same `BOT_TOKEN`, `WEBHOOK_SECRET`, and `WEBHOOK_URL` values:

```sh
php bin/set-webhook.php
```

You can also set the webhook with Telegram's Bot API using the same URL and the `secret_token` parameter. Do not include the bot token or webhook secret in screenshots or public logs.

## Background workers

The container runs:
- `bin/process-update-outbox.php` approximately once per minute.
- `bin/auto-delete-messages.php` approximately once every five minutes.

These workers restart automatically if they exit. They require the database and bot environment variables. Enable auto-delete in the bot settings only after testing it with a test chat; it deletes tracked collection-delivery messages and is not a substitute for testing message IDs and bot permissions.

## Verify the deployment

1. Open `https://YOUR-KOYEB-DOMAIN/health.php` and confirm it returns `ok`.
2. Confirm `https://YOUR-KOYEB-DOMAIN/install.php` is blocked; the web installer is intentionally disabled in this container.
3. Check Koyeb logs for the migration output, Apache startup, and worker output.
4. Send `/start` to the bot and test a private collection link before enabling auto-delete.

## Notes and limitations

- The Koyeb image runs Apache and both worker loops in one service. If you later scale to multiple instances, every instance would start workers too; keep one instance unless you move workers to a dedicated single-instance service.
- Local container files are ephemeral. Configuration is expected to come from environment variables; do not rely on files written by the web installer.
- Worker scheduling is best-effort and pauses when the service is stopped or redeploying. It is not a precise cron scheduler.
- The health endpoint checks PHP runtime/extensions, not database or Telegram connectivity.
- Koyeb plan availability, resource limits, and free-tier terms can change. Check the current Koyeb dashboard and pricing before relying on free hosting.
