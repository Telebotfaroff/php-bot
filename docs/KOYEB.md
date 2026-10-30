# Deploying on Koyeb with Aiven PostgreSQL

This branch deploys the Telegram file-store bot as a Docker service. Apache/PHP handles the webhook; Supervisor runs the durable update-outbox and auto-delete workers.

## Requirements
- An Aiven PostgreSQL service reachable from Koyeb.
- Telegram bot token, owner Telegram ID, webhook secret and public Koyeb domain.
- Deploy the \`deploy/koyeb\` branch using the repository Dockerfile.

## Create the Aiven database
1. Create an Aiven **PostgreSQL** service and wait until it is running.
2. Copy its PostgreSQL service URI from the connection information. It should begin with \`postgres://\` or \`postgresql://\` and include host, port, database, username and password.
3. Download the CA certificate from Aiven if you want full server-certificate verification.
4. Add the URI to Koyeb as secret \`DB_URL\`. Never commit credentials.

## Koyeb configuration
Use the Docker builder, expose port **8080** over HTTP and configure health check \`/health.php\` on port 8080.

| Variable | Required | Value |
|---|---:|---|
| \`BOT_TOKEN\` | Yes | Telegram token from BotFather |
| \`BOT_USERNAME\` | Yes | Username without @ |
| \`BOT_OWNER_ID\` | Yes | Numeric Telegram owner ID |
| \`WEBHOOK_SECRET\` | Yes | Random 32–256 character value using letters, digits, _ or - |
| \`WEBHOOK_URL\` | Yes | \`https://YOUR-KOYEB-DOMAIN/webhook.php\` |
| \`APP_ENV\` | Recommended | \`production\` |
| \`DB_URL\` | Yes | Complete Aiven PostgreSQL service URI as a Koyeb Secret |
| \`DB_SSL_MODE\` | Optional | Defaults to \`require\`; set \`verify-ca\` to verify with CA |
| \`DB_SSL_CA_PEM\` | Optional | Full public CA certificate PEM as a Koyeb Secret |
| \`DB_SSL_CA\` | Alternative | Path to readable CA certificate |
| \`AUTO_MIGRATE\` | Optional | Defaults to \`true\` |
| \`LOG_FILE\` | Optional | \`/dev/stderr\` |

The default \`require\` mode encrypts the connection without CA verification. For verification, set \`DB_SSL_MODE=verify-ca\` and provide \`DB_SSL_CA_PEM\` or \`DB_SSL_CA\`; the app uses PostgreSQL \`sslmode=verify-full\`.

Do not reuse the old MySQL URI. Aiven MySQL and Aiven PostgreSQL are separate services and use different drivers and ports.

## Migrations and startup
The Docker image installs PHP's \`pdo_pgsql\` extension. On startup, \`docker/entrypoint.sh\` runs \`php bin/migrate.php\` before Apache and workers start unless \`AUTO_MIGRATE=false\`. The migration runner translates the existing MySQL-style migration definitions to PostgreSQL DDL, creates indexes and updated-at triggers, and tracks applied files in \`schema_migrations\`.

Keep the Koyeb service at one instance while using built-in workers and startup migrations. If the database is unreachable or a migration fails, startup exits instead of serving an uninitialized bot.

## Webhook and workers
Set \`WEBHOOK_URL\` to the Koyeb HTTPS URL ending in \`/webhook.php\`, then register the webhook once with \`php bin/set-webhook.php\` using the same environment variables.

Workers:
- \`bin/process-update-outbox.php\` approximately every five seconds.
- \`bin/auto-delete-messages.php\` approximately every five minutes.

## Verify
1. Open \`https://YOUR-KOYEB-DOMAIN/health.php\`; it should return \`ok\`.
2. Check Koyeb logs for PostgreSQL connection and migration output.
3. Run \`php bin/check-deployment.php\` in CLI with the same secrets.
4. Send \`/start\` and test a collection link before enabling auto-delete.
