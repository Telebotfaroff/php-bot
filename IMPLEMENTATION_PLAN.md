# Implementation Plan — PHP Telegram File Store Bot

## Project constraints
- Runtime: PHP on shared cPanel hosting.
- Database: MySQL/MariaDB using PDO and prepared statements.
- Telegram integration: HTTPS webhook for updates; bounded cPanel cron jobs for retries and scheduled tasks.
- Do not require Redis, MongoDB, Docker, root access, or a permanently running daemon.
- Keep secrets in environment/config outside version control. Never commit real bot tokens, database passwords, webhook secrets, or provider credentials.
- Build incrementally. Complete and review one phase before starting the next.

## Product requirements
1. Admin-only `/post` wizard: optional collection thumbnail, title, multiple videos, review/preview, then explicit Publish.
2. The bot copies videos to the configured dump channel itself; the admin must not manually upload them to the dump channel. Persist Telegram file identifiers and ordering. Do not bulk-scan historical channel posts in v1.
3. Publish a title/thumbnail and deep link to the configured post channel. Links use cryptographically random, unguessable codes and remain valid as long as the collection record exists.
4. Opening a collection delivers every video in its original order. Split delivery into Telegram media groups of at most 10 items; track batches and retry safely to avoid blind duplicate sends.
5. Admin `/Settings` controls dump, post and force-subscription channels; message deletion timers; welcome coins; collection access cost; shortener settings; referral rules; Premium plans; and manual-payment instructions for UPI and cryptocurrency.
6. Force-subscription check uses Telegram's membership API and fails safely when membership cannot be verified.
7. Wallet: configurable one-time welcome credit; integer coin amounts; append-only ledger; atomic, idempotent debits/credits. Insufficient funds never cause a debit and show Get Coins plus View Premium Plans.
8. Get Coins: create a unique verification task and credit only after a provider-supported, server-to-server callback/API confirms completion. Validate signatures/tokens and make rewards idempotent. A click or browser return alone is not proof.
9. Premium: an active subscription bypasses the coin system completely and grants collection access without a coin debit. Expired Premium returns the user to normal coin checks. Plans have name, duration (or explicit lifetime), price, currency and enabled status. Plan edits affect future purchases by default; disabling a plan does not cancel active subscriptions.
10. Referrals: one successful referral grants the referrer 24 hours of Premium, not coins. Prevent self-referrals, duplicate rewards and existing-user referrals. Record attribution and reward events; qualification rule must be explicit and configurable.
11. Auto-delete: configurable per message category, stored in a durable queue with due time, attempts and retry handling. Deleting Telegram messages must not delete archive records.
12. Operational safety: deduplicate Telegram update IDs and callbacks; log safely without secrets; use transactions and locking for wallet/subscription changes; protect webhook with Telegram's secret-token header; keep webhook responses fast.

## Phases and exit criteria

### Phase 0 — Repository and architecture (completed)
- Inspect repository contents, default branch, existing commits and project conventions.
- Confirm shared-cPanel assumptions and list items that must be verified on the actual hosting account.
- Create `IMPLEMENTATION_PLAN.md` and `ARCHITECTURE.md`.
- No application implementation yet.
- Exit: architecture and phased plan committed. Repository began without application source; actual cPanel capabilities still need confirmation.

### Phase 1 — Runtime foundation and security (completed)
- Added lightweight PHP bootstrap/autoloading, environment/private-file configuration, JSON logging, Telegram webhook secret verification, Telegram Bot API client, secured webhook entrypoint, CLI-only webhook setup command, `.env.example`, `.gitignore`, README and webhook-secret smoke tests.
- GitHub Actions PHP syntax checks and Phase 1 smoke tests passed in the reported workflow run.
- Exit: syntax and webhook-secret smoke checks passed.

### Phase 2 — Database and update processing (implemented; CI reported passing by user)
- Added PDO MySQL/MariaDB connection factory, configurable database settings, versioned PHP migrations, CLI migration command, `users`, `telegram_updates`, and `bot_settings` tables, and transactional update persistence with duplicate-update protection.
- Added a MySQL-backed GitHub Actions integration test for migration idempotency, update deduplication and sender persistence.
- Exit: database migration and duplicate-update tests passed in the user's reported CI run; production migrations still must be applied to the target database.

### Phase 3 — Admin authorization and initial command router (implemented; user confirmed CI passed)
- Added owner-only authorization from `BOT_OWNER_ID`, normalized update context, persistent settings repository, and a read-only `/settings` view that hides sensitive setting values.
- Added `/start`, `/help`, `/id`, `/setting`, and `/settings` basic command handlers and a Phase 3 authorization/context test suite.
- The settings view is read-only; CRUD editing and the full bot features are not implemented yet.
- Exit: passed in the user's reported GitHub Actions run.


### Reliability fix — Durable Telegram update outbox (implemented; CI verification pending)
- Telegram webhook updates, sender profiles and serialized update payloads are committed atomically to `telegram_update_jobs` before attempting Bot API work.
- The webhook acknowledges after durable storage, even if immediate dispatch fails. Duplicate update IDs can retry the pending job rather than being dropped as already processed.
- Added row-lock claims, a 90-second lease, exponential retry backoff capped at one hour, and `bin/process-update-outbox.php` for bounded cPanel cron retries.
- Apply the new migration and configure the cron worker every minute after CI passes.
- Delivery is at-least-once, not exactly-once: a timeout after Telegram accepts a send can still cause a duplicate reply because Telegram send methods do not provide a general idempotency key.
- Exit: verify the newest GitHub Actions run passes before deployment.

### Phase 4 — Collection posting wizard and dump storage (implemented; CI verification pending)
- Added persistent draft state, optional thumbnail, title, multiple videos, automatic `copyMessage` storage in the configured dump channel, ordered file references, cancellation, and resumable drafts.
- Added preview and publish/cancel buttons. Drafts and ordered files are stored in MySQL; large media are not downloaded to cPanel disk.
- Added `/setting set dump_channel_id CHANNEL_ID` and `/setting set post_channel_id CHANNEL_ID` for validated channel configuration.
- Exit: verify the newest GitHub Actions run, then test on a private test bot and channels before production.

### Phase 5 — Publishing and deep links (initial publishing implemented; link delivery pending)
- Added explicit publish confirmation, cryptographically random 32-character collection codes, collection/file persistence, and a post-channel message with an `https://t.me/<bot_username>?start=post_<code>` link.
- Publishing records a stable collection ID before the external send and resumes a `publishing` draft after recoverable failures. A rare timeout after Telegram accepts the channel post can still duplicate that post on retry.
- Opening a deep link does not yet deliver videos; that requires the access-check and delivery phase.
- Exit: add deep-link resolution, access checks and ordered delivery in Phase 6.

### Phase 6 — Access checks and delivery (initial implementation committed; CI verification pending)
- Added `/start post_<code>` resolution for published collections, configurable force-subscription verification via `getChatMember`, and fail-closed behavior if Telegram cannot verify membership.
- Added active Premium lookup; active Premium bypasses coin cost. Added configurable `collection_access_cost` (defaults to 0/free), wallet balance locking, transactional coin debit and append-only ledger entry for paid access. Insufficient funds do not debit and show Get Coins / View Premium Plans placeholders.
- Added delivery jobs and per-file status/message tracking. Videos are copied sequentially from dump-channel messages in original order; failed jobs can resume without charging again for that incomplete job.
- Added ordered media-group delivery for compatible consecutive items (2–10 videos or documents per Telegram album); single items are copied individually. Remaining limitations: the actual wallet funding/Get Coins workflow, Premium plan/purchase management, delivery concurrency leases and auto-delete processing are not implemented. A network timeout after Telegram accepts a copy or album can still create a duplicate.
- Exit: add automated service tests for membership, premium bypass, coin debit, insufficient funds and retry; confirm latest GitHub Actions run passes; then test against a private bot and channels.

### Phase 7 — Wallet and Get Coins workflow
- Implement ledger, welcome reward, atomic debit/credit, insufficient-balance UI and verification-task records.
- Exit: concurrency/idempotency tests pass; unverified tasks never credit coins.

### Phase 8 — Premium plans and manual subscriptions
- Admin CRUD for plans and safe subscription expiry/extension.
- Users choose a plan, view admin-configured manual payment instructions, submit a transaction/reference and optional screenshot, and receive a pending request ID.
- Admin reviews pending requests and explicitly approves or rejects them. Only an authorized approval grants Premium; user-submitted screenshots/references alone never activate a plan.
- Record approver, decision time, plan/price snapshot, user-submitted reference and audit history. Prevent duplicate approvals from granting duplicate subscriptions.
- No payment gateway, payment-provider webhook, or automatic payment verification is required.
- Exit: active Premium bypasses coins; expiry restores normal rules; pending requests never grant access.

### Phase 9 — Shortener provider integration
- Implement one documented provider adapter at a time with server-side verification, replay protection and provider failure handling.
- Exit: simulated and real supported callbacks are validated; repeated callbacks cannot double-credit.

### Phase 10 — Referral rewards
- Attribution, qualification event, fraud guards and auditable 24-hour Premium reward.
- Exit: self-referral, repeat referral and duplicate event tests pass.

### Phase 11 — Deletion and background jobs
- Bounded cron workers, locking, retries, job status and deletion schedule processing.
- Exit: overlapping cron runs do not duplicate work; failures are visible and retryable.

### Phase 12 — Manual payment administration
- Add admin review commands/buttons for pending Premium purchase requests, including approve/reject and an optional admin note.
- Make decisions idempotent, auditable and restricted to authorized admins.
- Configure payment instructions in `/Settings`; do not store or request users' banking passwords, OTPs, card PINs or other payment secrets.
- Exit: only explicit admin approval grants a subscription, and repeated callbacks/actions cannot grant duplicate time.

### Phase 13 — Hardening and deployment
- Unit/integration tests, rate limits, privacy-safe logs, backup/restore notes, cPanel cron setup, webhook setup and troubleshooting.
- Exit: deployment checklist and known limitations documented; no secrets in repository.

## Required test coverage
- Collections containing 1, 2, 10, 11 and 23 videos.
- Ordering, optional thumbnail, draft interruption and copy failure.
- Duplicate webhook updates, shortener reward events and manual-payment approval actions.
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
- Open decisions (shortener provider, UPI/crypto manual-payment instructions, actual cPanel PHP/cron/outbound HTTPS support):
- Phase 0 exit status:


## Manual payment policy (confirmed)
- Supported manual payment methods: UPI and cryptocurrency. Admin can configure instructions for each method (for example, UPI ID/QR instructions and the supported crypto network/address); never hard-code the user's actual addresses into source code.
- The project will not integrate a payment gateway/provider.
- Premium purchases are handled manually by an administrator.
- The bot lets the user choose UPI or cryptocurrency and displays the corresponding instructions configured by the admin, records a user's plan selection and submitted transaction reference, and marks the request pending.
- An authorized admin verifies the payment outside the bot and approves or rejects the request inside the bot.
- Only the approval action activates Premium. Never activate Premium merely because the user sends a screenshot, transaction ID, or claims payment succeeded.
- Approval must be transactional and idempotent, with an audit record and a snapshot of the requested plan and price.
