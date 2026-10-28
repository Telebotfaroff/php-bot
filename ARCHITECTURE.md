# Architecture — PHP Telegram File Store Bot

## 1. Deployment model

Target: shared cPanel hosting with PHP and MySQL/MariaDB.

- Telegram sends updates to a public HTTPS webhook endpoint such as `public/webhook.php`.
- The webhook validates Telegram's secret-token header, records/deduplicates the update, performs only bounded work, and responds quickly.
- cPanel cron runs bounded workers for queued jobs, scheduled message deletion, subscription expiry, retries and housekeeping.
- Use database-backed jobs and locks rather than Redis or a long-running process.
- Store application code outside the public web root where cPanel permits. Expose only the webhook endpoint and any deliberately public static assets.
- Use HTTPS, least-privilege database credentials, PHP PDO prepared statements and a supported PHP version.
- Do not assume Composer, shell access, FFmpeg, a custom PHP binary, or a specific cPanel feature exists until verified.

## 2. Proposed project layout

```text
php-bot/
├── public/
│   └── webhook.php
├── bin/
│   ├── worker.php
│   ├── process-deletions.php
│   ├── retry-jobs.php
│   └── set-webhook.php
├── src/
│   ├── Bootstrap.php
│   ├── Config/Config.php
│   ├── Database/Connection.php
│   ├── Telegram/
│   │   ├── BotApiClient.php
│   │   ├── UpdateRouter.php
│   │   └── CallbackRouter.php
│   ├── Security/
│   │   ├── AdminAuthorizer.php
│   │   └── WebhookVerifier.php
│   ├── Repositories/
│   ├── Services/
│   ├── Handlers/
│   └── Support/
├── migrations/
├── tests/
├── storage/logs/       # writable, not public
├── .env.example
├── .gitignore
├── composer.json       # only if Composer is available/desired
├── IMPLEMENTATION_PLAN.md
├── ARCHITECTURE.md
└── README.md
```

This is a target structure, not a claim that these files already exist. Phase 0 should adapt it to the actual repository and hosting constraints.

## 3. Main request flows

### Admin collection publishing
1. Admin sends `/post`; server-side authorization checks the Telegram user ID.
2. Draft state is persisted in MySQL. The bot asks for an optional collection thumbnail, then title, then one or more videos.
3. For each video, the bot copies it to the configured dump channel using the Telegram Bot API. Save the returned dump message/file metadata and its sequence number. Do not ask the admin to upload the same file manually to the dump channel.
4. Admin selects Done; the bot displays a preview. Only explicit Publish makes the collection public.
5. Create a cryptographically random deep-link code, store it with the collection, and publish the title, optional thumbnail and deep link to the configured post channel.
6. Failures leave a resumable draft or retryable job; they must not silently create duplicate published collections.

### Collection access
1. User opens `/start post_<code>`.
2. Resolve the code using a prepared query; reject invalid/unpublished collections.
3. Apply force-subscription check if enabled.
4. If Premium is active, grant access without reading/debiting the coin wallet for the access charge.
5. Otherwise, check balance and atomically debit the configured access cost. If insufficient, do not debit and show Get Coins and View Premium Plans.
6. Send videos in original order, splitting into groups of at most 10 items where Telegram media-group methods are supported.
7. Track delivery batches and outcomes so retries do not blindly repeat successful batches. Report partial failures clearly.

### Get Coins verification
1. Create a unique, expiring verification task associated with user and intended reward.
2. Generate a provider URL using the provider's documented integration.
3. Credit coins only after a server-side callback/API confirms completion and the event passes signature/token, expiry, and replay checks.
4. In one database transaction, record the unique reward event and append the ledger credit. A repeated event cannot award coins twice.
5. Tell the user the updated balance and provide a button to retry the original collection link.

A browser return, button click, or client-supplied success parameter is not proof of completion.

### Manual Premium purchases, subscriptions and referrals
- The bot does not use a payment gateway or payment-provider integration. Supported manual methods are UPI and cryptocurrency.
- Admin settings store separate UPI and cryptocurrency instructions. For UPI, the admin may configure a UPI ID and instructions or QR image; for crypto, configure the accepted network and wallet address. These are settings, not source-code constants. A user selects a Premium plan and payment method, then receives the matching instructions, and submits a transaction/reference plus optional screenshot. The bot creates a pending purchase request; this submission does not grant Premium.
- An authorized admin checks the payment outside the bot and explicitly approves or rejects the request. Approval must be transactional, idempotent and audited, including the approving admin, timestamp, selected plan/price snapshot, submitted reference and decision. Duplicate approval actions must not grant duplicate subscription time.
- Subscription records store start/end times and the plan/terms used at purchase.
- Active Premium means access bypass only; it is not a coin package or an extra-perks bundle.
- A referral reward is a separate auditable event granting the referrer 24 hours of Premium after the configured qualifying action. If Premium is active, extend from the current expiry; otherwise start from the current time.
- Prevent self-referrals, repeated attribution and repeated rewards with database constraints and transactions.

### Auto-delete
- When a bot message is sent, optionally enqueue a deletion record with chat ID, message ID, due time, status and retry count.
- Cron selects due records with locking, calls Telegram deleteMessage, and records success or retryable failure.
- Message deletion does not delete collection, file, wallet, subscription or audit records.

## 4. Database design outline

Use InnoDB and UTC timestamps. Exact columns, indexes and migrations are Phase 2 work; this is the initial logical model.

| Table | Purpose |
|---|---|
| `users` | Telegram user ID, first-seen time, welcome reward status and referral attribution |
| `settings` | Persistent settings such as dump/post/force-subscribe channel IDs and feature values |
| `collection_drafts` | Admin wizard state and resumable draft metadata |
| `collections` | Published/draft collection title, random public code, optional thumbnail and status |
| `collection_files` | Ordered video references, dump-channel message IDs, Telegram file IDs and metadata |
| `telegram_updates` | Unique update IDs for idempotent webhook processing |
| `wallets` | Current integer coin balance per user; updated transactionally with ledger |
| `wallet_ledger` | Append-only credit/debit audit trail with unique source/event key |
| `verification_tasks` | Shortener task token, user, expected reward, expiry and verification status |
| `provider_events` | Unique provider event IDs and verification outcomes to prevent replay |
| `premium_plans` | Name, duration/lifetime flag, price, currency and enabled state |
| `subscriptions` | User, plan snapshot/terms, start, expiry and status |
| `premium_purchase_requests` | User, selected plan/price snapshot, submitted reference, optional screenshot file ID, pending/approved/rejected status, reviewer, decision time and audit notes |
| `referrals` | Referrer, referred user, attribution time and qualification status |
| `referral_rewards` | Unique reward event and 24-hour Premium grant audit |
| `delivery_jobs` | Collection delivery state and batch-level progress for retry safety |
| `jobs` | General durable work queue with attempts, next run, lock and error metadata |
| `scheduled_deletions` | Telegram chat/message IDs, due time, status and retries |
| `admin_audit_log` | Sensitive admin actions and settings changes, without secrets |

### Integrity rules
- Telegram user IDs and update IDs are stored in a type large enough for Telegram identifiers.
- Use unique constraints for update IDs, collection codes, provider event IDs, reward source keys, and one-time referral qualification/reward rules.
- Wallet amounts are integers; every balance mutation has a matching ledger row in the same transaction.
- Use row locks or equivalent transactional protection when debiting coins and extending Premium.
- Keep published collection codes random and unguessable; do not expose sequential database IDs as public links.
- Store Telegram file IDs and dump message references rather than downloading large video files to the shared host.

## 5. Services and boundaries

- `BotApiClient`: Telegram HTTPS calls, timeouts, API error normalization and rate-limit handling.
- `WebhookVerifier`: validates configured Telegram secret token.
- `UpdateRouter` / handlers: routes commands, messages and callbacks; handlers should delegate business logic to services.
- `AdminAuthorizer`: server-side owner/admin allowlist.
- Repositories: prepared SQL and persistence only.
- `PostWizardService`: draft state machine and validation.
- `StorageService`: copies media into the dump channel and persists returned references.
- `PublicationService`: preview confirmation, random deep-link generation and post-channel publishing.
- `AccessService`: subscription check, Premium bypass and coin access decision.
- `DeliveryService`: ordered batch sends and retry state.
- `WalletService`: atomic balance changes and ledger entries.
- `ShortenerService`: provider adapter interface and verified reward handling.
- `PremiumService`: plan management, subscription state and expiry.
- `ManualPaymentService`: create pending purchase requests, store payment instructions, and apply admin approve/reject decisions exactly once.
- `ReferralService`: attribution, qualification and Premium reward.
- `SettingsService`: validated persistent settings.
- `DeletionScheduler` and worker: durable scheduled deletion.
- `Logger` / `Clock` / `RandomCode`: shared support functions.

## 6. Security and reliability

- Keep bot token, DB password, webhook secret, shortener credentials and payment secrets out of Git.
- Validate webhook secret-token header using a constant-time comparison where applicable.
- Deduplicate updates before performing side effects; acknowledge callbacks safely.
- Authorize admin actions on the server for every update, not by hiding buttons.
- Use PDO prepared statements, input validation, output escaping where HTML/Markdown is used, and bounded API timeouts.
- Treat callback data, deep-link payloads, provider callbacks and user messages as untrusted input.
- Verify provider signatures and unique event IDs; reject expired/replayed tasks.
- Do not grant Premium from an unverified payment redirect.
- Use bounded cron execution, a lock/lease to avoid overlapping workers, retry backoff and a maximum attempt count.
- Redact credentials and sensitive callback payloads from logs.
- Provide database backup and restore guidance before production use.

## 7. cPanel checks required before implementation

Confirm these on the actual hosting account:
1. PHP version and required extensions (`curl`, `pdo_mysql`, `json`, `mbstring`, `openssl`).
2. MySQL/MariaDB version and ability to create database/users.
3. HTTPS domain and ability to receive Telegram webhook requests.
4. cPanel Cron Jobs availability and supported PHP CLI path; if CLI is unavailable, determine a safe web-triggered alternative without exposing worker endpoints.
5. Outbound HTTPS access from PHP to Telegram Bot API and chosen provider.
6. Composer availability, or whether dependencies must be avoided/bundled.
7. PHP execution-time and memory limits; webhook must remain fast and workers bounded.
8. Ability to store logs and configuration outside public web root, or suitable access protections if hosting layout prevents this.
9. Telegram bot permissions in dump and post channels, including ability to send/copy messages and delete its own messages where needed.
10. The chosen shortener provider's documented server-side verification method. Admins must configure UPI instructions and the accepted cryptocurrency network/address; no payment-provider webhook is needed.

## 8. Decisions intentionally left open
- Shortener provider and its exact server-side verification contract.
- UPI instructions/QR, accepted cryptocurrency network and wallet address, displayed plan prices/currency, and authorized admin(s) who review requests.
- Actual cPanel PHP/CLI/cron/outbound-network capabilities.
- Whether Composer is available and which test runner can run in the environment.
- Exact message deletion categories and durations, which should be configurable in admin settings.


## 9. UPI and cryptocurrency manual payment request lifecycle

1. User opens Premium plans and selects an enabled plan.
2. Bot creates a pending purchase request with a snapshot of the plan name, duration, price and currency, then displays admin-configured payment instructions.
3. User submits a transaction/reference and may attach a Telegram screenshot. The bot stores the request as pending and informs the admin. The attachment/reference is evidence for review, not proof of settlement.
4. An authorized admin reviews the payment outside the bot and selects Approve or Reject. Optional notes may be stored for audit.
5. Approval and subscription creation happen in one database transaction. A unique decision/approval key prevents repeated clicks or duplicate Telegram updates from granting Premium twice. The bot notifies the user of the result.
6. Rejected requests do not grant Premium. A user who made a genuine payment can contact the admin or submit a new request according to the configured policy.

Recommended additional integrity rules:
- Use a `premium_purchase_requests` status transition such as `pending -> approved|rejected`; do not permit a second terminal decision to silently overwrite the first.
- Restrict approve/reject callbacks to configured admins and verify authorization on every action.
- Keep an audit log for creation, review and decision; do not expose one user's payment reference or screenshot to other users.
- Do not collect OTPs, PINs, passwords, card CVVs or banking login credentials.
