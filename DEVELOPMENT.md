# Aṣẹ API — Developer Guide

This repository contains the **Aṣẹ** financial platform API (see `README.md`
for the full project charter & technical specification). It implements
**Phase 1 (Foundation)** and the **Phase 2 (Financial Core)** of the
implementation roadmap: the domain architecture, double-entry ledger,
wallets with atomic reservations, the transaction state machine, idempotent
financial APIs, the provider framework (with a deterministic mock provider),
the transactional outbox, KYC tiers, the risk engine, reconciliation, audit
logging and a full automated test suite.

## Stack

| Component | Technology |
| --- | --- |
| Backend | Laravel 11, PHP 8.3 |
| Database | PostgreSQL 16 (system of record) |
| Cache / Queue / Locks | Redis 7 + Laravel Horizon (never the source of financial truth) |
| Auth | Laravel Sanctum (Bearer tokens) |
| API | REST/JSON, consistent envelope, OpenAPI in `docs/openapi.yaml` |
| Testing | PHPUnit (unit + feature), PHPStan/Larastan, Pint |
| CI/CD | GitHub Actions (tests + static analysis) |
| Local dev | Docker Compose (app, nginx, postgres, redis, horizon) |

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
docker compose exec app php artisan db:seed --force   # system accounts + bill catalog

# 4. API is now at http://localhost:8080
curl http://localhost:8080/api/v1/health
```

`make up` / `make test` / `make analyse` / `make seed` wrap the common
commands (see the `Makefile`).

### Without Docker

Run PHP 8.3 + PostgreSQL 16 + Redis 7 locally, point `.env` at them, then:

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --force && php artisan db:seed
php artisan serve        # http://127.0.0.1:8000
php artisan horizon      # queue workers (optional in dev)
```

## Running the tests

The suite runs against **PostgreSQL 16** (real row locks + check
constraints + ledger immutability triggers):

```bash
# with docker compose services up:
make test                       # composer test (artisan test)
make analyse                    # phpstan (Larastan) + pint
```

GitHub Actions runs the same matrix on every push/PR (PostgreSQL 16 +
Redis 7 service containers, PHP 8.3).

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
`ASE_MOCK_FUNDING_MODE`): `success` | `failure` | `timeout`.

- Bill purchase with a phone ending in **888** → definitive failure.
- Bill validate for a phone ending in **999** → customer not found.
- `timeout` mode → ambiguous outcome → the transaction enters `VERIFYING`,
  funds stay **reserved**, and nothing fails over to another provider.

## Money, invariants & safety rules

- All amounts are **integer minor units** (kobo for NGN). No floats.
- Every money movement posts **balanced double-entry ledger entries**
  (`SUM(debits) = SUM(credits)`); entries are **append-only** (DB trigger on
  PostgreSQL rejects UPDATE/DELETE).
- Wallet debits are protected by **atomic reservations**
  (`SELECT ... FOR UPDATE` + `CHECK` constraint `reserved <= control`).
- All financial mutation endpoints are **idempotent** (`Idempotency-Key`
  header; same key + different body is rejected).
- Provider outcomes are classified **DEFINITIVE_SUCCESS /
  DEFINITIVE_FAILURE / AMBIGUOUS**; ambiguous outcomes are verified, never
  failed over.
- Critical state changes commit atomically with an **outbox event**; the
  outbox publisher fans events out to notifications.
- Every state transition is recorded in `transaction_events`; security and
  financial actions are written to `audit_logs`.

See `docs/ARCHITECTURE.md` for the full financial-core design and
`docs/openapi.yaml` for the API contract.

## Repository layout

```
app/
├── Application/        Commands, Queries, DTOs (use-cases entry points)
├── Console/Commands    wallets:expire-reservations, outbox:publish,
│                       transactions:verify-stale, reconciliation:run
├── Domain/             Business domains (Users, Authentication, Wallet,
│                       Ledger, Transactions, Payments, Bills, Betting,
│                       Providers, KYC, Risk, Notifications,
│                       Reconciliation, Audit)
├── Exceptions/         FinancialException + concrete error codes
├── Http/               Controllers (Api/V1), Middleware, Requests, Resources
├── Infrastructure/     Provider implementations (mocks), outbox publisher,
│                       storage & messaging adapters
└── Models/             Eloquent models (ledger entries are append-only)
database/migrations     Core tables + integrity constraints
tests/                  Unit + feature tests (PostgreSQL-backed)
```
