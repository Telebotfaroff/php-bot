# Deploying on Koyeb

This repository includes a Docker deployment for Koyeb. It serves the Telegram webhook with Apache/PHP and runs the durable update-outbox worker and auto-delete worker as supervised processes in the same service.

## Before you start

You need:
- A Koyeb account and access to deploy a service from this GitHub repository.
- An external MySQL 8 or compatible MariaDB database reachable from Koyeb. The current schema uses MySQL-specific migrations; PostgreSQL is not supported by this branch.
- A Telegram bot token from BotFather and the numeric Telegram user ID of the owner.

The Koyeb service does not include a database. This branch supports a complete MySQL service URL (including Aiven's `mysql://...` URI) through `DB_URL`.

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
| `DB_URL` | Yes (recommended) | Complete MySQL service URI copied from Aiven; store it as a Koyeb secret |
| `DB_CHARSET` | Recommended | `utf8mb4` |
| `DB_SSL_MODE` | Optional | URI's `ssl-mode=REQUIRED` is detected automatically; otherwise set `required` |
| `DB_SSL_CA_PEM` | Recommended | Full PEM text of the CA certificate downloaded from Aiven; use a Koyeb secret |
| `DB_SSL_CA` | Alternative | Path to a CA certificate file inside the container |
| `AUTO_MIGRATE` | Optional | Defaults to `true`; runs pending migrations before Apache/workers start |
| `LOG_FILE` | Optional | `/dev/stderr` or a writable path; platform logs are preferred |

### Aiven MySQL setup

1. In Aiven Console, open the MySQL service's **Overview** page and copy the complete **Service URI**. It contains the hostname, service-specific port, username, password, database and `ssl-mode=REQUIRED`.
2. In Koyeb, add `DB_URL` as a **Secret** and paste the complete URI. Do not split it into host/user/password variables when using this option.
3. Download the CA certificate from Aiven's service Overview page. Add its complete PEM contents as the Koyeb secret `DB_SSL_CA_PEM`. This enables CA certificate verification. The app writes the secret to a permission-restricted temporary file at runtime and passes its path to PDO.
4. Leave `DB_SSL_MODE` unset when the URL contains `ssl-mode=REQUIRED`; the application detects it and enforces TLS. You may explicitly set `DB_SSL_MODE=required`.
5. Deploy. The container runs the migrations automatically on startup unless `AUTO_MIGRATE=false`.

If you only configure `ssl-mode=REQUIRED`, traffic is encrypted but the server certificate is not verified. Supplying `DB_SSL_CA_PEM` enables certificate verification. Aiven's PHP guide recommends downloading the CA certificate and configuring the PDO connection to verify it. See [Aiven: Connect to Aiven for MySQL with PHP](https://aiven.io/docs/products/mysql/howto/connect-with-php) and [Aiven TLS/SSL certificates](https://aiven.io/docs/platform/concepts/tls-ssl-certificates).

The URI parser accepts the `mysql://` scheme and URL-decodes credentials. Keep the URI in Koyeb Secrets, not in Git, logs, screenshots or source code. If a password contains reserved URL characters, the provider-generated URI should percent-encode them; do not manually concatenate an unescaped password.

### Alternative split variables

If you do not want to use `DB_URL`, set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD`. Set `DB_SSL_MODE=required` and provide `DB_SSL_CA_PEM` or `DB_SSL_CA` for certificate verification.

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
- `bin/process-update-outbox.php` approximately once every five seconds.
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
