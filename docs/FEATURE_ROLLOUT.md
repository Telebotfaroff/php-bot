# Feature rollout plan

Target branch: `deploy/koyeb`. Runtime remains PHP 8.3 + Apache on port 8080, PDO MySQL/Aiven TLS, and the existing Telegram webhook/outbox worker.

## Stage 1 — Search, analytics, and moderation
- Search published collections by title, return permanent Telegram deep links, and log query hashes (not raw search terms).
- Owner-only usage dashboard and user inspection/ban/unban commands.
- Additive MySQL migration only; preserve current promo, wallet, settings, and delivery tables.

## Stage 2 — Referral and rewards hardening
- Reuse the existing referral attribution/reward tables and the current 24-hour Premium reward.
- Add configurable qualification and anti-abuse checks only after reviewing the current /start flow.
- Never credit rewards from client-side claims alone.

## Stage 3 — Backup and restore
- Implemented owner-only `/backup` in a private bot chat; it sends a logical JSON export directly to the owner and deletes the temporary server file.
- Sensitive `bot_settings` entries whose keys contain token, secret, password, API key, private key, credential, or webhook are excluded.
- Includes exported tables, row counts, UTC creation time, and applied migration records; upload is capped at 8 MB.
- Before delivery, a non-destructive dry run validates JSON format/version, table names, row counts, and column names; the owner receives a table/row summary. This validation does not modify the database.
- Restore/import remains disabled. A real restore still requires uploaded-file staging, explicit confirmation, transactional safeguards, and a rollback plan; never import an untrusted backup directly.
- This is a convenience export, not a substitute for Aiven provider backups; large datasets require a database-provider export.

## Stage 4 — Reliability and operations
- Queue health and failed-job visibility, safe retries, duplicate-update handling, bounded worker batches.
- Add automated tests for search, ban enforcement, and admin-only commands; run GitHub Actions before deployment.

## Deployment checklist
1. Confirm the Koyeb service deploys `deploy/koyeb` and exposes HTTP port 8080.
2. Let the entrypoint apply pending migrations; verify migration 011 in logs.
3. Check the latest PHP checks workflow and test on a private test bot before production use.
4. Keep credentials in Koyeb environment variables and rotate any credentials previously exposed.
