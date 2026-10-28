# Implementation Plan — PHP Telegram File Store Bot

## Project constraints
- Runtime: PHP on shared cPanel hosting.
- Database: MySQL/MariaDB using PDO and prepared statements.
- Telegram integration: HTTPS webhook for updates; bounded cPanel cron jobs for retries and scheduled tasks.
- Do not require Redis, MongoDB, Docker, root access, or a permanently running daemon.
- Keep secrets in environment/config outside version control. Never commit real bot tokens, database passwords, webhook secrets, or payment credentials.
- Build incrementally. Complete and review one phase before starting the next.

## Product requirements
1. Admin-only `/post` wizard: optional collection thumbnail, title, multiple videos, review/preview, then explicit Publish.
2. The bot copies videos to the configured dump channel itself; the admin must not manually upload them to the dump channel. Persist Telegram file identifiers and ordering. Do not bulk-scan historical channel posts in v1.
3. Publish a title/thumbnail and deep link to the configured post channel. Links use cryptographically random, unguessable codes and remain valid as long as the collection record exists.
4. Opening a collection delivers every video in its original order. Split delivery into Telegram media groups of at most 10 items; track batches and retry safely to avoid blind duplicate sends.
5. Admin `/Settings` controls dump, post and force-subscription channels; message deletion timers; welcome coins; collection access cost; shortener settings; referral rules; and Premium plans.
6. Force-subscription check uses Telegram's membership API and fails safely when membership cannot be verified.
7. Wallet: configurable one-time welcome credit; integer coin amounts; append-only ledger; atomic, idempotent debits/credits. Insufficient funds never cause a debit and show Get Coins plus View Premium Plans.
8. Get Coins: create a unique verification task and credit only after a provider-supported, server-to-server callback/API confirms completion. Validate signatures/tokens and make rewards idempotent. A click or browser return alone is not proof.
9. Premium: an active subscription bypasses the coin system completely and grants collection access without a coin debit. Expired Premium returns the user to normal coin checks. Plans have name, duration (or explicit lifetime), price, currency and enabled status. Plan edits affect future purchases by default; disabling a plan does not cancel active subscriptions.
10. Referrals: one successful referral grants the referrer 24 hours of Premium, not coins. Prevent self-referrals, duplicate rewards and existing-user referrals. Record attribution and reward events; qualification rule must be explicit and configurable.
11. Auto-delete: configurable per message category, stored in a durable queue with due time, attempts and retry handling. Deleting Telegram messages must not delete archive records.
12. Operational safety: deduplicate Telegram update IDs and callbacks; log safely without secrets; use transactions and locking for wallet/subscription changes; protect webhook with Telegram's secret-token header; keep webhook responses fast.

## Phases and exit criteria

### Phase 0 — Repository and architecture (current)
- Inspect repository contents, default branch, existing commits and project conventions.
- Confirm shared-cPanel assumptions and list items that must be verified on the actual hosting account.
- Create `IMPLEMENTATION_PLAN.md` and `ARCHITECTURE.md`.
- No application implementation yet.
- Exit: report findings, decisions, unresolved provider/hosting choices, and wait for review.

### Phase 1 — Runtime foundation and security
- Add minimal PHP bootstrap, environment/config loading, structured logging, safe error handling, webhook verification and Telegram Bot API client.
- Add `.env.example`, `.gitignore`, and deployment notes. Never commit secrets.
- Exit: syntax checks and tests for config validation and webhook authentication.

### Phase 2 — Database and update processing
- Add versioned SQL migrations, PDO connection, repositories, update-id deduplication and basic user records.
- Exit: migrations apply cleanly; duplicate Telegram updates are harmless.

### Phase 3 — Admin authorization and settings
- Secure owner/admin commands and persistent settings for channel IDs and feature values.
- Exit: unauthorized users cannot invoke admin actions; settings survive restarts.

### Phase 4 — Collection posting wizard and dump storage
- Implement draft state machine, thumbnail skip, title, multiple videos, bot-managed copying to dump channel, ordering, cancellation and recovery.
- Exit: interrupted drafts can resume/cancel; failed copies are recoverable; no accidental publication.

### Phase 5 — Publishing and deep links
- Preview draft, publish only after confirmation, create random collection code and publish to post channel.
- Exit: published links resolve to the correct persistent collection.

### Phase 6 — Access checks and delivery
- Implement force-subscription, active-Premium bypass, coin affordability checks and ordered batch delivery with retry tracking.
- Exit: no charge on rejected access; Premium users never lose coins for access.

### Phase 7 — Wallet and Get Coins workflow
- Implement ledger, welcome reward, atomic debit/credit, insufficient-balance UI and verification-task records.
- Exit: concurrency/idempotency tests pass; unverified tasks never credit coins.

### Phase 8 — Premium plans and subscriptions
- Admin CRUD for plans and safe subscription expiry/extension.
- Integrate payment only after payment provider and webhook contract are chosen.
- Exit: active Premium bypasses coins; expiry restores normal rules.

### Phase 9 — Shortener provider integration
- Implement one documented provider adapter at a time with server-side verification, replay protection and provider failure handling.
- Exit: simulated and real supported callbacks are validated; repeated callbacks cannot double-credit.

### Phase 10 — Referral rewards
- Attribution, qualification event, fraud guards and auditable 24-hour Premium reward.
- Exit: self-referral, repeat referral and duplicate event tests pass.

### Phase 11 — Deletion and background jobs
- Bounded cron workers, locking, retries, job status and deletion schedule processing.
- Exit: overlapping cron runs do not duplicate work; failures are visible and retryable.

### Phase 12 — Optional payments
- Add a payment provider only after explicit provider selection and verified webhook documentation.
- Exit: no subscription is granted from an unverified client redirect.

### Phase 13 — Hardening and deployment
- Unit/integration tests, rate limits, privacy-safe logs, backup/restore notes, cPanel cron setup, webhook setup and troubleshooting.
- Exit: deployment checklist and known limitations documented; no secrets in repository.

## Required test coverage
- Collections containing 1, 2, 10, 11 and 23 videos.
- Ordering, optional thumbnail, draft interruption and copy failure.
- Duplicate webhook updates, callbacks and shortener reward events.
- Concurrent wallet debits, insufficient balance and no negative balances.
- Active Premium bypass, expiry, and no coin debit while Premium is active.
- Referral self-attribution and duplicate qualification.
- Telegram API failures, deletion retries, webhook secret validation and admin authorization.
- cPanel execution-time constraints and overlapping cron workers.

## Phase 0 report template
- Repository/branch/commit findings:
- Existing code and conventions:
- Hosting assumptions to verify:
- Architecture decisions:
- Open decisions (shortener provider, payment provider, actual cPanel PHP/cron/outbound HTTPS support):
- Phase 0 exit status:
