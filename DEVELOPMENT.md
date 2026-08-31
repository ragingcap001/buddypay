# Aṣẹ API — Developer Guide

This repository contains the **Aṣẹ** financial platform API (see `README.md`
for the full project charter & technical specification). It implements
**Phase 1 (Foundation)** and the **Phase 2 (Financial Core)** of the
implementation roadmap: the domain architecture, double-entry ledger,
wallets with atomic reservations, the transaction state machine, idempotent
financial APIs, the provider framework, the transactional outbox, KYC tiers,
the risk engine, reconciliation, audit logging and a full automated test
suite.

On top of that it now carries live provider rails (Wema, Monnify, Kuda),
admin-managed runtime configuration, FCM push delivery, and a Filament
admin panel.

> **Read `## Current status` before trusting anything to work end to end.**
> Several parts of this guide describe intent that is not yet verified.

## Stack

| Component | Technology |
| --- | --- |
| Backend | Laravel 11, PHP 8.3 |
| Database | PostgreSQL 16 (system of record) |
| Cache / Queue / Locks | Redis 7 + Laravel Horizon (never the source of financial truth) |
| Customer auth | Laravel Sanctum (Bearer tokens) |
| Admin panel | Filament 3 at `/admin`, on its own `admin` session guard |
| API | REST/JSON, consistent envelope, OpenAPI in `docs/openapi.yaml` |
| Testing | PHPUnit (unit + feature), PHPStan/Larastan, Pint |
| CI/CD | **Not enabled** — workflow parked at `.github/workflows/ci.yml.disabled` |
| Local dev | Docker Compose (app, nginx, postgres, redis, horizon) |

### PHP version pinning

`composer.json` sets `config.platform.php` to **8.3.33**, matching the
`php:8.3-fpm-alpine` image. Composer therefore resolves for the container
regardless of the PHP on your machine. Without this, running
`composer require` on a newer local PHP silently writes a `composer.lock`
that the Docker image cannot satisfy (the build then dies in
`vendor/composer/platform_check.php`).

If you deliberately move the runtime PHP version, change it in **both**
the `Dockerfile` and `composer.json`, then re-resolve.

## Quick start (Docker)

```bash
# 1. Start PostgreSQL + Redis + app stack
docker compose up -d

# 2. Create the test database (used by the automated suite)
docker compose exec postgres createdb -U buddypay buddypay_test

# 3. Configure & boot the app
cp .env.example .env
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force   # system accounts, bill catalog, providers, dev admin

# 4. API is now at http://localhost:8080
curl http://localhost:8080/api/v1/health
```

`make up` / `make test` / `make analyse` / `make seed` wrap the common
commands (see the `Makefile`; targets: `up down logs ps env-test-db test
analyse migrate seed serve shell horizon`).

### Without Docker

Run PHP 8.3 + PostgreSQL 16 + Redis 7 locally, point `.env` at them, then:

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --force && php artisan db:seed
php artisan serve        # http://127.0.0.1:8000
php artisan horizon      # queue workers (optional in dev)
```

## Admin panel

The admin UI is **Filament 3**, served at `/admin`.

```
http://localhost:8080/admin
```

Local/dev credentials come from `AdminSeeder` (local & testing
environments only — it no-ops elsewhere):

```
admin@ase.local  /  password
```

Create real accounts inside the panel under **Platform → Staff accounts**.

### Staff identity is separate from customers

Panel logins are `App\Models\Admin` rows in the **`admins`** table, on a
dedicated `admin` session guard (`config/auth.php`). This is deliberate:
`users` holds customer PII, KYC records and wallet ownership, and must not
share a credential store or login surface with staff.

The older `users.role` column, the `EnsureAdmin` middleware and
`php artisan users:make-admin` still exist and still gate the
**`/api/v1/admin/*` JSON API** — that path is unchanged. Only the
server-rendered Blade dashboard was retired when Filament took over
`/admin`.

Because `app_config.updated_by` is a foreign key to `users`, the panel
writes `null` there rather than a staff id (which would otherwise point at
an unrelated customer). Attribution for panel actions lives in
`audit_logs`, which stores a polymorphic `actor_type` + `actor_id` and so
records the `Admin` correctly.

### What the panel exposes

| Section | Capability |
| --- | --- |
| Money → Transactions | **Read-only.** Filter by status/type/provider/date; per-transaction status history (`transaction_events`) and every provider attempt. |
| Money → Wallets | **Read-only.** Control / reserved / available balances, plus reservations. |
| Customers → Users | Edit name, email and account status only. Never PIN or password. Not creatable or deletable. |
| Platform → Runtime config | Per-group forms for `wema`, `kuda`, `monnify`, `firebase`, `apple`, `google`. |
| Platform → Providers | Status, circuit-breaker state, 24h attempt/failure counts, and the enable/disable lever. |
| Platform → Staff accounts | Full CRUD for panel logins. |

Transactions and wallets are read-only **by design**: a transaction's
status is only ever correct as an output of `TransactionStateMachine`, and
a balance only as a side effect of the ledger. An out-of-band edit would
leave the ledger and the transaction disagreeing.

### Runtime configuration

`AppConfigService` resolves every managed value as
**DB override → environment variable → default**, so operators can change
provider credentials without a redeploy. Values are stored encrypted in
`app_config`.

Secret handling in the panel mirrors the admin API:

- secrets are **never** rendered back to the browser — only a mask,
- submitting a **blank** secret means *leave unchanged*, not *clear*,
- saves are audit-logged with the actor and the keys touched, **never the
  values**.

## Running the tests

The suite runs against **PostgreSQL 16** (real row locks + check
constraints + ledger immutability triggers):

```bash
# with docker compose services up:
make test                       # composer test (artisan test)
make analyse                    # phpstan (Larastan) + pint
```

There is **no CI pipeline running yet** — nothing gates a push.

A ready-to-use workflow is parked at
`.github/workflows/ci.yml.disabled` (PostgreSQL 16 + Redis 7 services,
PHP 8.3, `artisan test` + PHPStan + Pint). It is inert two ways: the
`.disabled` suffix stops GitHub parsing it at all, and the contents are
commented out. Enabling it is a rename plus an uncomment — the file's
header has the steps and explains the `--no-security-blocking` flag it
needs while the framework pin is unresolved.

## Trying the API end to end (mock providers)

```bash
# 1. Register (the response contains dev_otp in local/testing envs)
curl -s -X POST http://localhost:8080/api/v1/register \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"firstName":"Chidi","lastName":"Okafor","email":"chidi@example.com","phone":"08031234567","gender":"male","password":"secret123","passwordConfirmation":"secret123"}'

# 2. Verify the email OTP (use the dev_otp value from step 1)
curl -s -X POST http://localhost:8080/api/v1/verify-email \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"chidi@example.com","otp":"<dev_otp>"}'
# -> returns token (Bearer token)

# 3. Set a transaction PIN (sensitive operations require it) — first time
# only; an existing PIN must go through /v1/user/reset-pin instead.
curl -s -X POST http://localhost:8080/api/v1/user/set-pin \
  -H "Authorization: Bearer <token>" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"transactionPin":"1234","transactionPinConfirm":"1234"}'

# 4. Fund the wallet (idempotent — send a unique Idempotency-Key per request)
curl -s -X POST http://localhost:8080/api/v1/wallet/fund \
  -H "Authorization: Bearer <token>" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H 'Idempotency-Key: fund-0001' -H 'X-Transaction-Pin: 1234' \
  -d '{"amount":100000}'        # 100000 kobo = ₦1,000.00

# 5. Buy airtime
curl -s -X POST http://localhost:8080/api/v1/bills/pay \
  -H "Authorization: Bearer <token>" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H 'Idempotency-Key: airtime-0001' -H 'X-Transaction-Pin: 1234' \
  -d '{"type":"AIRTIME","amount":50000,"phone":"08039990001"}'

# 6. Inspect
curl -s http://localhost:8080/api/v1/wallet/balance        -H "Authorization: Bearer <token>" -H 'Accept: application/json'
curl -s http://localhost:8080/api/v1/transactions          -H "Authorization: Bearer <token>" -H 'Accept: application/json'
```

### Mock provider behaviours (dev/test only)

Config: `config/ase.php` → `ase.mock` (env `ASE_MOCK_MODE`,
`ASE_MOCK_FUNDING_MODE`, `ASE_MOCK_PAYOUT_MODE`):
`success` | `failure` | `timeout`.

- Bill purchase with a phone ending in **888** → definitive failure.
- Bill validate for a phone ending in **999** → customer not found.
- `timeout` mode → ambiguous outcome → the transaction enters `VERIFYING`,
  funds stay **reserved**, and nothing fails over to another provider.

Payout tests must pass `"provider": "mock"` in the request body —
otherwise `ase.default_payout_provider` sends them at the real Wema rail.

## Providers

| Provider | Role | Selection |
| --- | --- | --- |
| `mock` | bills, funding, payouts (dev/test) | default in local |
| `wema` | funding (payment requests) + payouts | `ASE_DEFAULT_PAYOUT_PROVIDER` |
| `monnify` | funding (one-time bank transfer account) + disbursements | per-request / config |
| `kuda` | bills — airtime, data, electricity, betting, cable | `"provider": "kuda"` on `POST /bills/pay`, default for `/v1/{airtime,data,electricity,betting,cable}/*` |
| `reloadly` | gift cards | `ase.default_giftcard_provider` |

All external calls funnel through `ProviderGateway`, which enforces
provider status and the circuit breaker, records a `provider_attempts` row
for **every** call, and classifies the outcome. Unknown or in-flight
provider states resolve to **AMBIGUOUS** — the platform verifies rather
than guessing, and never fails over an ambiguous transaction to a second
provider.

## Mobile API contract

The customer-facing API is being aligned to a full contract handed down
by the mobile team (`payloads.md`) — exact routes, field names/casing,
and response shapes, since a real client is being built against it. This
is a **presentation-layer rewrite**: `routes/api.php`, controllers,
Form Requests and Resources change to match; the ledger, wallet
reservations, state machine, idempotency and provider gateway underneath
are untouched and are called from the new controllers exactly as before.

Delivered in phases, each reviewed before the next starts:

**Phase 1 (done)** — session lifecycle, self-service account, notifications:
- Auth is now **email**-first, not phone: `POST /v1/register` (issues an
  email OTP, no token yet) → `POST /v1/verify-email` (issues the token) →
  `POST /v1/login` (email + password). `POST /v1/resend-email-otp`,
  `/v1/forgot-password` → `/v1/verify-reset-otp` → `/v1/reset-password`
  replace the old random-token reset link — password reset now reuses the
  same `OtpChallenge` mechanism as email verification (the
  `password_reset_tokens` table is dropped, not just unused).
- `users.fpuid` (`FP` + zero-padded id, assigned in a model `created`
  hook — no separate counter table, so no race to guard against),
  `first_name`/`last_name` (`name` stays, kept in sync via a `saving`
  hook — Filament tables and existing services still read it),
  `gender`, `device_token`.
- `/v1/user/profile` (GET/PUT), `/v1/user/change-password`,
  `/v1/user/update-device-token` — new; didn't exist before.
- PIN: `set-pin`/`verify-pin` already existed (as `/v1/auth/pin` and
  `/v1/auth/verify-pin`) and just moved under `/v1/user/*` with the
  contract's field names. `reset-pin` (old PIN → new PIN) is new.
  **`set-pin` now refuses to run if a PIN is already set** (409, directing
  to `reset-pin`) — the contract doesn't specify this, but letting a bearer
  token alone silently overwrite an existing PIN would be a real
  hole; changing one now requires proving you know the current one.
- `/v1/preferences` (public, no auth) — feature flags + socials, in a new
  `platform_preferences` table with a Filament page to edit them.
  Deliberately **not** part of `AppConfigService`: that service is for
  encrypted, masked infra/provider secrets; this is public, non-secret
  product config, different audience and blast radius.
  `bettingCharge` in the response is **not stored** — it's read live from
  `config('ase.fees.betting.flat')`, the exact figure `FeeCalculation`
  already charges, so the two numbers can't drift apart.
- In-app notifications (`/v1/user/notifications`, `mark-all-read`,
  `{id}/mark-read`) run on Laravel's native database-notification channel
  (`App\Notifications\V1\*`), not a bespoke table — its pagination and
  row shape already match the contract exactly. This is separate from
  `NotificationService`'s SMS/push/log delivery pipeline, which still
  runs unchanged alongside it; the database notification is written
  "solely for the in-app section", per the contract, so it exists even
  for a user who never opted into push.

**Phase 2 (done)** — dedicated purchase routes for airtime, data,
electricity, betting, cable, sitting alongside (not replacing) the
generic `POST /v1/bills/pay`; the contract-shaped `/v1/user/transactions`
list. All of it calls the same `BillPaymentService`/`ProviderGateway`
underneath — no change to reservation, ledger, state-machine or
provider-attempt behaviour.

- **`RequirePin` now accepts the PIN from either place** — the
  `X-Transaction-Pin` header (unchanged, still used by wallet/fund,
  wallet/payout, bills/pay) or a `pin` field in the JSON body (what every
  new purchase route sends), header taking priority if both are present.
- **No Idempotency-Key header exists in this contract** for any purchase
  route. Rather than drop duplicate-submission protection,
  `SyntheticIdempotencyKey` derives a key from a hash of the request body
  itself — an identical resubmission (the double-tap this exists to catch)
  hashes the same and is short-circuited by the existing
  `IdempotencyService`; a genuinely different purchase hashes differently
  and proceeds. No client cooperation required, and `/v1/bills/pay`'s
  header-based flow is untouched.
- **`/v1/user/transactions` and `/v1/user/transactions/{transId}` are
  scoped to bill-payment types only** (airtime/data/electricity/betting/
  cable) — matching every example the contract gives for this endpoint.
  Wallet funding gets its own history endpoint in Phase 3.
  `oldBalance`/`newBalance` are captured once, in `BillPaymentService`'s
  atomic-initiation block, as the wallet's available balance immediately
  before/after the reservation — and never revised afterwards, including
  on a later failure/release. That isn't a shortcut: the contract's own
  example rows show a *failed* electricity purchase still carrying the
  balance drop from its (since-reversed) reservation, which is exactly
  what this produces.
- **`GET /v1/user/profile`'s `transactions` now returns the 5 most recent
  bill-payment transactions**, through the same `MobileTransactionResource`
  shape as `/v1/user/transactions`. `walletFundings` stays `[]` — there's
  no funding-history table to query yet; that's Phase 3.
- **Kuda catalog field names for a biller's icon/min/max amount are
  best-effort, unconfirmed guesses** (`MobileCatalogService`), following
  the exact same lenient multi-key-candidate pattern already used
  elsewhere in the Kuda integration — same caveat already recorded above
  for the rest of Kuda, now extended to these fields specifically.
- **Electricity's async token delivery is not wired up.** The purchase
  response's `token` field is always present (per contract) but always
  `null` — Kuda delivers it later via TSQ/webhook, and backfilling it
  requires adding a `providerMetadata` field to `BillVerificationResponse`
  (verification currently only returns outcome/reference/error). Scoped
  out rather than half-built.
- **`transId` in the contract is the transaction's existing `reference`
  field, unchanged** (`ASE_T_<ulid>`, not UUID-shaped like the contract's
  own examples). Reshaping `ReferenceGenerator` to emit UUIDs would touch
  webhooks, ledger references, audit logs and tests throughout the app for
  a purely cosmetic win — not worth the blast radius. `transId` is an
  opaque identifier either way.
- The dash-guard bug flagged earlier against `KD-VTU-MTNNG`-style
  identifiers (see the provider cross-check above) turned out to already
  be fixed in the working tree by the time this phase started — the
  UUID-shaped check is in place in `KudaBillProvider::findCatalogBillItem`.
  Network-prefix detection was pulled out into `NetworkDetector` (used by
  both `KudaBillProvider` and the new `GET /v1/detect-network` endpoint)
  so the two can't drift apart.

**Phase 3 (done)** — wallet funding rework + gift cards.

- **The Phase 2 checkpoint's premise about wallet funding was wrong, and
  it's worth recording why.** Before live docs, the contract's
  generate-account/pending-fund/requery/confirm-payment/retry/fundings
  flow looked like Monnify's Reserved/Dedicated Virtual Account product,
  gated on confirming it was enabled on the account in use. Fetching the
  actual Monnify API reference (2026-08-28) showed that's the wrong
  product entirely: Reserved Accounts require the customer's **BVN on
  every creation call** — a hard requirement this contract's flow has no
  step for collecting, and a persistent per-customer account wouldn't
  naturally expire every ~40 minutes the way the contract's own examples
  show. The actual match is Monnify's **"Pay With Bank Transfer"**
  two-step flow — `POST /api/v1/merchant/transactions/init-transaction`
  then `POST /api/v1/merchant/bank-transfer/init-payment` — which mints a
  one-time dynamic account per transaction, no BVN, and whose documented
  response (`accountDurationSeconds: 2400`, `expiresOn`, `accountNumber`)
  matches the contract's example values exactly, including the specific
  2400-second duration. No external product confirmation was actually
  needed — building this was never blocked.
- This also exposed a live bug in the already-partially-fixed
  `MonnifyPaymentProvider`: `charge()` was reading `accountNumber`/
  `bankName`/`bankCode` off the **first** call's response, which per the
  verified docs never carries those fields (only the second call does) —
  so a real funding attempt would have returned an empty account every
  time. Fixed by adding `MonnifyClient::initBankTransferPayment()` and
  calling it as the required second step; `MonnifyFundingTest`'s fixture
  (which already asserted a specific `account_number` no fake ever
  provided) is corrected to match.
- New `WalletFundingController` (`/v1/wallet/monnify/*`, 7 routes) is a
  presentation layer over the existing `FundingService` — the same
  idempotency/risk/ledger-crediting path every funding rail already uses;
  `retry` transitions the superseded attempt to `FAILED` and calls
  `FundingService::execute()` again; `requery`/`confirm-payment` both
  call the existing `FundingService::verifyReference()` ("ask the
  provider, never guess"), differing only in response wording per the
  contract. No `pin` on `/fund` — funding adds money, it doesn't move any
  out — and no `Idempotency-Key` header, since the contract's payloads
  never send one; `SyntheticIdempotencyKey` covers it instead, same as
  Phase 2's purchase routes.
- **Gift cards (Reloadly)** — a full new provider integration, verified
  against Reloadly's live API reference (2026-08-28): OAuth2
  client-credentials against `auth.reloadly.com` (separate from the
  gift-cards base URL, which becomes the token's `audience`), a new
  `TransactionType::GiftCard`, `GiftCardProviderInterface` +
  `ReloadlyGiftCardProvider` wired through `ProviderGateway` exactly like
  every other provider (circuit breaker, `provider_attempts`, outcome
  classification — a 4xx from Reloadly is a definitive pre-charge
  rejection via `ProviderDeclinedException`, matching the same pattern
  Kuda/Monnify use). `GiftCardPurchaseService` mirrors
  `BillPaymentService`'s orchestration shape.
  - **Redemption codes get their own encrypted table**
    (`gift_card_redemptions`, `card_number`/`pin_code` on the `encrypted`
    Eloquent cast), not the shared `transactions.metadata` JSON blob every
    other provider writes to in plain text — a redemption code is the
    redeemable value itself, the same class of secret as a provider
    credential in `app_config`.
  - **Pricing is not a guessed formula.** The contract's per-denomination
    breakdown (`baseNgnPrice`/`serviceFee`/`total`) uses Reloadly's own
    `fixedRecipientToSenderDenominationsMap` for FIXED products (exact,
    no extra call) or a live `GET /fx-rate` call for RANGE products
    (authoritative, always current) — not a reverse-engineered fee
    formula. `serviceFee` is this platform's own markup,
    `config('ase.fees.giftcard')`, same bps/flat pattern as every other
    transaction type.
  - **A real state-machine bug was caught and fixed before it ran**: the
    first draft of `GiftCardPurchaseService::applyOutcome()`'s "still
    ambiguous" branch tried to transition `VERIFYING → AMBIGUOUS` when
    re-verifying a still-unresolved purchase — not a legal transition
    (`TransactionStateMachine` only allows `AMBIGUOUS → VERIFYING`, never
    the reverse, by design). Every reconciliation pass on a
    still-ambiguous gift card purchase would have thrown. Fixed to match
    `BillPaymentService::applyVerification()`'s existing pattern exactly:
    no-op, stay in `VERIFYING`.
  - Not built: a "view my redemption code again later" endpoint — the
    contract only shows the code in the immediate purchase response, and
    `/v1/user/transactions` (scoped to bill-payment types) doesn't list
    gift card purchases at all, matching every example the contract gives.

**While this phase was in progress, a teammate pushed a commit
(`b3925b8`, not yet merged into this branch) deepening Kuda's response
parsing and fixing `nameEnquiry`** — different code regions than anything
touched here (Kuda's `validateCustomer`/`purchase`/`classifyBillResponse`/
`findCatalogBillItem` vs. this phase's `MonnifyClient`/`GiftCards`/
`WalletFundingController`), so finishing this phase before merging was
the lower-risk order. Worth a deliberate merge — not a fast-forward, both
branches have unique commits — before the next phase starts.

## Money, invariants & safety rules

- All amounts are **integer minor units** (kobo for NGN). No floats.
  Kuda additionally rejects non-whole-Naira amounts rather than rounding.
- Every money movement posts **balanced double-entry ledger entries**
  (`SUM(debits) = SUM(credits)`); entries are **append-only** (DB trigger on
  PostgreSQL rejects UPDATE/DELETE).
- Wallet debits are protected by **atomic reservations**
  (`SELECT ... FOR UPDATE` + `CHECK` constraint `reserved <= control`).
  `WalletService::commit()` lowers `control_balance` and `reserved_balance`
  in a **single statement** — dropping one first trips the check constraint
  whenever a reservation covers the whole balance.
- Payout reservations carry a **24h TTL** (NIP can take hours). If a
  provider confirms success *after* the reservation lapsed, the transaction
  **fails loudly** and is flagged for reconciliation — it is never booked
  COMPLETED without ledger entries.
- All financial mutation endpoints are **idempotent** (`Idempotency-Key`
  header; same key + different body is rejected).
- Webhook success events whose reported amount does not match the initiated
  amount are **refused**; the transaction stays `VERIFYING` and is flagged.
- Critical state changes commit atomically with an **outbox event**; the
  outbox publisher fans events out to notifications.
- Every state transition is recorded in `transaction_events`; security and
  financial actions are written to `audit_logs`.

See `docs/ARCHITECTURE.md` for the full financial-core design,
`docs/PROVIDERS.md` for provider specifics and `docs/openapi.yaml` for the
API contract.

## Repository layout

```
app/
├── Application/        Commands, Queries, DTOs (use-cases entry points)
├── Console/Commands    outbox:publish, payouts:authorize, reconciliation:run,
│                       transactions:verify-stale, users:make-admin,
│                       wallets:expire-reservations
├── Domain/             Business domains (Users, Authentication, Wallet,
│                       Ledger, Transactions, Payments, Bills, Betting,
│                       Providers, KYC, Risk, Notifications, Config,
│                       Reconciliation, Audit)
├── Exceptions/         FinancialException + concrete error codes
├── Filament/           Admin panel resources, pages & relation managers
├── Http/               Controllers (Api/V1), Middleware, Requests, Resources
├── Infrastructure/     Provider implementations (Mock, Wema, Monnify, Kuda),
│                       outbox publisher, storage & messaging adapters
├── Models/             Eloquent models (ledger entries are append-only)
└── Providers/Filament  AdminPanelProvider (panel config + guard)
database/migrations     Core tables + integrity constraints
tests/                  Unit + feature tests (PostgreSQL-backed)
```

## Current status

Honest state of the branch, so nobody assumes more than is true.

**Verified**

- Schema builds clean from empty (`migrate:fresh`), seeders run.
- Full PHP syntax sweep passes; no duplicate method declarations.
- Filament routes register; the `/api/v1/admin/*` API is intact alongside.
- Composer resolves against PHP 8.3 (matches the Docker image).
- **Upgraded to Laravel 12.68.0 (2026-08-29)**, resolving CVE-2026-48019 and
  the other advisories that `^11.31` couldn't avoid. `composer audit` now
  reports clean. `nunomaduro/larastan` (abandoned, capped at Laravel 11)
  was swapped for the maintained `larastan/larastan` (`^3.10`, which also
  required bumping `phpstan/phpstan` to `^2.2` — two config keys removed in
  PHPStan 2.0, `checkMissingIterableValueType`/`checkGenericClassInNonGenericObjectType`,
  were dropped from `phpstan.neon.dist` accordingly, their behaviour now
  folded into rule levels rather than user-configurable). Filament 3,
  Sanctum, Horizon, and Tinker needed no constraint changes — their
  existing ranges already resolved Laravel-12-compatible versions.
  **Laravel 13 is not yet reachable**: Filament 3 caps at
  `illuminate/support ^12.0` and has no Laravel 13 support (v3 is in
  feature-freeze), so reaching 13 needs a Filament 4/5 migration first —
  tracked as a separate, later item, not bundled with this security-driven
  upgrade.

**Not verified**

- **The Filament panel has not been exercised against a running database.**
  Routes resolve and the code parses, but no page has been loaded, no form
  saved, and no login performed.
- **Provider integrations were cross-checked against the public docs on
  2026-08-27 (two passes) and 2026-08-28 (Monnify funding, see Phase 3
  below).** Kuda is aligned (auth, envelope, short refs, TSQ-first
  settlement, webhook headers; a second pass then verified the
  bill-operation request/response shapes — all Kuda request fields matched
  the docs exactly, but four response-parsing gaps were found and fixed:
  the documented `Data.Billers[].BillItems[]` catalog nesting was never
  unwrapped so airtime auto-resolution could not find item identifiers,
  `Data.CustomerName` was lost on validation, a boolean `Status: false`
  verification was misread as valid, and `Data.Reference` from the purchase
  receipt was never captured as `provider_reference`; the client now parses
  the documented envelope and flat shapes leniently). Airtime
  auto-resolution also no longer rejects valid dashed identifiers such as
  `KD-VTU-MTNNG` (only UUID-shaped values are excluded; network detection
  itself now lives in the shared `NetworkDetector`, used by both this
  provider and `GET /v1/detect-network`). Monnify had four contract bugs
  in the collection/checkout path, all fixed: auth was `POST
  /api/v1/auth/login` with Basic `base64(apiKey:secretKey)` (not
  `/api/v2/oauth/token`), checkout is `POST /api/v1/merchant/transactions/
  init-transaction` with `paymentReference`/`currencyCode` returning
  `checkoutUrl`, verify is `GET /api/v2/merchant/transactions/query`
  reading `paymentStatus`, and name enquiry is `GET /api/v2/disbursements/
  account/validate?accountNumber=…&bankCode=…` (not `/api/v2/transfer/
  name-enquiry/{bank}/{account}`). A fifth Monnify bug, in the funding
  flow specifically, was found and fixed on 2026-08-28: see Phase 3 below.
  **Wema needs confirmation of the subscribed product** (see the warning in
  `docs/PROVIDERS.md`): the code targets the subscription-key ALAT API,
  while the portal also documents an AES-encrypted NIP Merchant-Payout
  product. Until Wema confirms, treat Wema traffic as UAT-only.
- **The Docker image has not been built successfully in this environment.**
  No Docker daemon/CLI is available here to attempt it. The Dockerfile's
  dependency-install step was fixed on 2026-08-29 to `COPY composer.json
  composer.lock ./` + `composer install` (composer.lock has been committed
  for a while; the Dockerfile just hadn't caught up) instead of resolving
  fresh with `composer update` at build time — a real reproducibility gap
  (the image could silently drift from what was actually tested) — but the
  build itself is still unproven end to end.
- **The test suite is not green.** Current full run (2026-08-29, against a
  local Postgres 16): **69 passing / 101 failing, 170 total** — the suite
  has grown substantially since the last count here. **Confirmed unrelated
  to the Laravel 12 upgrade**: the identical test run (same 170 tests, same
  69/101 split, same failing test names) was reproduced against the prior
  Laravel 11.56.1 lock before restoring the upgrade — the Laravel bump
  changed nothing about pass/fail outcomes. The dominant failure mode is
  `UnknownLedgerAccountException` on `WALLET:{id}`, spread across nearly
  every feature area (Kuda, Monnify, Wema, FCM, registration, admin config,
  idempotency, outbox) — consistent with one shared test-fixture gap
  (likely: factories creating `Wallet` rows without going through
  `WalletService::createUserWallet()`, which is what actually creates the
  matching ledger account) rather than 101 unrelated bugs. Not investigated
  further as part of the Laravel upgrade — see TODO.

## TODO

### Blocking a green build

- [x] **Fix the test fixtures (~15 bugs).** These fail for reasons unrelated
      to the app code — all fixed (2026-08-27), pending a green run:
  - [x] `Transaction::where(...)->fresh()` — `fresh()` is a model method,
    needs `->first()` first (15 call sites across 8 test files).
  - [x] `Http::fake()` closures typed `: Response` — `Http::response()`
    returns a Guzzle promise, so every typed fake threw a TypeError before
    the test body ran (4 closures).
  - [x] The same closures reference enclosing variables without `use
    (...)` — audited; the only closure with an external variable
    (`KudaBillTest::fakeKuda`) already had `use ($byServiceType)`.
  - [x] `MoneyTest`: `Money::naira(1).isLessThan(...)` — `.` should be `->`.
  - [x] `CircuitBreakerTest` / `FeeCalculationTest` override `setUp()`
    without `parent::setUp()`, so the container never boots and `config()`
    fails.
  - [x] Wema tests never set `ase.wema.api_key`, so the real client refused
    before the HTTP fake engaged (set in both Wema test `setUp()`s).
  - [x] Webhook signature tests don't seed the provider row, so the handler
    404s before signature validation runs (`WebhookTest` now seeds
    `ProviderSeeder`).
  - [x] `BankTransferTest` sets `ase.mock.payout_mode` but never sends
    `"provider": "mock"`, so it exercised the real Wema rail (all 8 payout
    bodies now pin the mock rail).
  - [x] `KycFlowTest` document upload sent JSON headers on a multipart
    request, dropping the file (upload now sends without Content-Type).
  - [x] `HealthTest` pointed the DB at a nonexistent database and never
    restored it, so `RefreshDatabase` teardown failed (config restored in
    `finally`).
  - [x] Tests that authenticate as two users in one method need
    `Auth::forgetGuards()` between requests — audited: the two affected
    tests (`IdempotencyTest`, `PushTest`) use explicit per-request Bearer
    tokens, not `actingAs()`, so no guard memoization applies.
- [ ] Run `migrate:fresh --seed` + the full suite and get it green.
- [ ] Load `/admin`, log in, and exercise each resource and the config form.

### Infrastructure

- [x] **Turn CI on.** Renamed to `ci.yml` and uncommented on 2026-08-29.
      Still may be blocked on the GitHub App lacking the `workflows`
      permission — if the push of this file itself fails or is rejected,
      grant the permission or push it manually from an account that has it.
  - [x] Framework advisory resolved (Laravel 12.68.0) — the
        `--no-security-blocking` flag is gone from the install step, and
        `composer audit` is now a real (fatal) step, not advisory-only.
  - [ ] Once the codebase has had a Pint sweep, drop `continue-on-error`
        from the code-style step to make it enforcing.
- [ ] **Build the Docker image end to end** and confirm the platform pin
      holds inside the container. The install step was fixed to use the
      committed `composer.lock` (2026-08-29), but the build itself is still
      unproven — no Docker daemon available in this environment either.
- [ ] `composer analyse` fails project-wide on Pint formatting (pre-existing,
      unrelated to any recent change). Decide whether to reformat in one
      sweep or relax the rules.
- [x] ~~`laravel/framework` is pinned `^11.31`...~~ **Upgraded to 12.68.0
      on 2026-08-29** (see Current status). Laravel 13 deliberately not
      pursued yet — blocked on Filament 3 having no Laravel 13 support;
      that's its own future migration, not folded into this one.
- [ ] **Get the test suite green.** 69/170 passing as of 2026-08-29 (see
      Current status) — confirmed unaffected by the Laravel 12 upgrade, so
      this is unblocked and independent work, not something the upgrade is
      waiting on. Likely one shared root cause (`WalletService::createUserWallet()`
      not being used by whatever creates test wallets) rather than 101
      separate bugs — worth checking that hypothesis first before fixing
      failures one by one.

### Correctness / product decisions

- [ ] **Funding fee is credited to the customer.** `FundingService` calls
      `wallets->fund($amount + $fee, …)`, so the customer receives the fee
      too. Dormant only because `wallet_funding` is `bps 0, flat 0` — the
      moment a funding fee is set, the platform pays it. Product call on
      whether to credit `amount` and book the fee to revenue.
- [ ] **Wema 429 (rate limit) classifies as a definitive failure.** Safe
      (funds return to the user) but there is no `RETRYING` state, so users
      must re-initiate.
- [ ] **Wema webhook payload shape is assumed**, not confirmed against a
      real sandbox callback. Verify when test credentials exist.
- [x] **Monnify name-enquiry path** — verified against the current docs
  (2026-08-27) and corrected: it is the free "Validate Bank Account" API,
  `GET /api/v2/disbursements/account/validate?accountNumber=…&bankCode=…`.
  (Client method only, unused in current flows.)
- [x] **Kuda request/response shapes** — cross-checked against Kuda's
  public API reference (2026-08-27): request fields matched exactly; four
  response-parsing gaps found and fixed (documented `Data.*` nesting for
  the catalog, validation name, boolean `Status: false`, and purchase
  `Data.Reference`). See `docs/PROVIDERS.md` and Current status. The
  Business API v2.1 portal docs are not public — UAT traffic remains the
  final confirmation.
- [x] `$transaction->metadata + $response->providerMetadata` in
  `BillPaymentService` would fatal if `metadata` were ever `null` (the
  column is nullable and Laravel's array cast passes null through) —
  guarded with `?? []` in both `BillPaymentService` and `FundingService`
  (2026-08-27).

### Hardening before public exposure

- [ ] **No MFA on the admin panel login**, and the panel is served from the
      same host as the API. Acceptable for a small trusted team; harden
      before exposing publicly.
- [ ] Panel authorisation is all-or-nothing — every `Admin` row can do
      everything (`Admin::canAccessPanel()` returns `true`). Add roles or
      permissions when more than one privilege level is needed.
- [ ] `AppConfigService::get()` hits the database on every call with no
      cache. Fine at current scale; add a short-TTL cache before it matters.
- [ ] KYC documents are metadata-only in the panel. Viewing an identity
      document needs an authenticated, signed, audited download route —
      deliberately not a plain link to the private disk.
