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
| CI/CD | **None yet** — see TODO |
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
# 1. Register (the response contains data.dev_otp in local/testing envs)
curl -s -X POST http://localhost:8080/api/v1/auth/register \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Chidi Okafor","phone":"08031234567","password":"secret123","password_confirmation":"secret123"}'

# 2. Verify the OTP (use the dev_otp value from step 1)
curl -s -X POST http://localhost:8080/api/v1/auth/verify-otp \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"phone":"08031234567","otp":"<dev_otp>"}'
# -> returns data.token (Bearer token)

# 3. Set a transaction PIN (sensitive operations require it)
curl -s -X POST http://localhost:8080/api/v1/auth/pin \
  -H "Authorization: Bearer <token>" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"password":"secret123","pin":"1234"}'

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
| `monnify` | funding (bank transfer in) + disbursements | per-request / config |
| `kuda` | bills — airtime, data, betting | `"provider": "kuda"` on `POST /bills/pay` |

All external calls funnel through `ProviderGateway`, which enforces
provider status and the circuit breaker, records a `provider_attempts` row
for **every** call, and classifies the outcome. Unknown or in-flight
provider states resolve to **AMBIGUOUS** — the platform verifies rather
than guessing, and never fails over an ambiguous transaction to a second
provider.

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

**Not verified**

- **The Filament panel has not been exercised against a running database.**
  Routes resolve and the code parses, but no page has been loaded, no form
  saved, and no login performed.
- **The Kuda integration is not validated against Kuda's own API docs.**
  `docs.kuda.com` is a client-rendered SPA that cannot be read without a
  browser, so request/response shapes are as-implemented, not as-specified.
- **The Docker image has not been built successfully in this environment.**
  The base-image pull could not complete behind the local proxy. The
  Dockerfile and the platform pin are believed correct but unproven.
- **The test suite is not green.** Last full run: **65 passing / 77
  failing**. Most failures are broken test fixtures rather than broken
  application code (see TODO).

## TODO

### Blocking a green build

- [ ] **Fix the test fixtures (~15 bugs).** These fail for reasons unrelated
      to the app code:
  - `Transaction::where(...)->fresh()` — `fresh()` is a model method, needs
    `->first()` first (several files).
  - `Http::fake()` closures typed `: Response` — `Http::response()` returns
    a promise, so every fake throws before the test body runs.
  - The same closures reference enclosing variables without `use (...)`.
  - `MoneyTest`: `Money::naira(1).isLessThan(...)` — `.` should be `->`.
  - `CircuitBreakerTest` / `FeeCalculationTest` override `setUp()` without
    `parent::setUp()`, so the container never boots and `config()` fails.
  - Wema tests never set `ase.wema.api_key`, so the real client refuses
    before the HTTP fake engages.
  - Webhook signature tests don't seed the provider row, so the handler
    404s before signature validation runs.
  - `BankTransferTest` sets `ase.mock.payout_mode` but never sends
    `"provider": "mock"`, so it exercises the real Wema rail.
  - `KycFlowTest` document upload sends JSON headers on a multipart request,
    dropping the `type` field.
  - `HealthTest` points the DB at a nonexistent database and never restores
    it, so `RefreshDatabase` teardown fails.
  - Tests that authenticate as two users in one method need
    `Auth::forgetGuards()` between requests — Sanctum's `RequestGuard`
    memoizes the first resolved user for the life of the guard.
- [ ] Run `migrate:fresh --seed` + the full suite and get it green.
- [ ] Load `/admin`, log in, and exercise each resource and the config form.

### Infrastructure

- [ ] **Turn CI on.** The workflow is written and parked at
      `.github/workflows/ci.yml.disabled`; nothing gates a push until it is
      enabled. Blocked on the GitHub App lacking the `workflows` permission —
      grant it, or commit the enabled file manually from an account that has
      it. Then: rename to `ci.yml`, uncomment, push.
  - [ ] Once the framework advisory is resolved, delete the
        `--no-security-blocking` flag from the install step so new advisories
        fail the build again.
  - [ ] Once the codebase has had a Pint sweep, drop `continue-on-error`
        from the code-style step to make it enforcing.
- [ ] **Build the Docker image end to end** and confirm the platform pin
      holds inside the container.
- [ ] `composer analyse` fails project-wide on Pint formatting (pre-existing,
      unrelated to any recent change). Decide whether to reformat in one
      sweep or relax the rules.
- [ ] `laravel/framework` is pinned `^11.31`, and **every** version that
      constraint can resolve to carries an unpatched advisory, including
      **CVE-2026-48019** (CRLF injection in the default email rule). Fixes
      only exist in 12.60+/13.10+. Composer's advisory check has to be
      bypassed to install at all. This is a framework-upgrade decision.

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
- [ ] **Monnify name-enquiry path is unverified** against the live API
      (client method only, unused in current flows).
- [ ] **Kuda request/response shapes are unverified** against the official
      docs (see Current status).
- [ ] `$transaction->metadata + $response->providerMetadata` in
      `BillPaymentService` would fatal if `metadata` were ever `null` (the
      column is nullable and Laravel's array cast passes null through). Not
      reachable today — metadata is always set at creation — but it is a
      latent trap if that ever changes.

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
