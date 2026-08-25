# Aṣẹ Financial Core — Architecture Notes

This document explains how the scaffold implements the mandatory
architectural principles from the charter (`README.md`, §71) and where each
principle is enforced in code.

## 1. Domain layout

The backend is organised by business domain (charter §18):

```
Domain/           pure business logic + contracts (no controllers)
Application/      command/query objects — the entry points to use cases
Infrastructure/  adapters: provider implementations, outbox publisher
Http/            transport concerns: controllers, requests, resources
```

Financial services (`WalletService`, `LedgerService`, `BillPaymentService`,
`FundingService`) never reference provider implementations — only the
contracts in `Domain/Providers` and `Domain/Payments`. Concrete providers are
wired by name in `config/ase.php` and resolved by
`Infrastructure/Providers/ProviderFactory`.

## 2. Money (charter §19)

`App\Domain\Wallet\ValueObjects\Money` is the only money type in the domain.
Amounts are integer minor units (kobo); float arithmetic is impossible by
construction. DB columns are `unsignedBigInteger`. Fee calculation uses
`intdiv(amount * bps, 10000) + flat` — pure integer math.

## 3. Double-entry ledger (charter §20)

`App\Domain\Ledger\Services\LedgerService::post()`:

1. requires ≥ 2 lines, positive integer amounts,
2. verifies `SUM(DEBITS) === SUM(CREDITS)` — otherwise
   `LedgerNotBalancedException` and **nothing is written**,
3. requires all accounts to exist,
4. posts a `ledger_transactions` row + `ledger_entries` rows inside the
   caller's database transaction (atomic with the financial state it
   represents).

**Immutability** is enforced twice:
- model level: `LedgerEntry` has no `updated_at` (append-only intent),
- database level (PostgreSQL): triggers `ase_ledger_entries_no_update` /
  `..._no_delete` raise an exception on any UPDATE/DELETE.

Customer wallet accounts are **liability** accounts (`WALLET:{user_id}`):
the platform owes the customer their balance.

### Posting conventions

| Event | Debit | Credit |
| --- | --- | --- |
| Wallet funding (A) | `FUNDING_RECEIVABLE` A | `WALLET:{user}` A |
| Bill payment (amount A, fee F) | `WALLET:{user}` A+F | `PROVIDER_PAYABLE` A + `REVENUE_TRANSACTION_FEE` F |
| Reversal/refund | the inverse, posted as a NEW ledger transaction (entries are never edited) | |

## 4. Wallets & reservations (charter §21–22, §40)

`wallets(control_balance, reserved_balance, currency)` with a DB
`CHECK (control >= 0 AND reserved >= 0 AND reserved <= control)`.

`available = control - reserved`.

`WalletService::reserve()` re-locks the wallet row
(`SELECT ... FOR UPDATE`) inside the caller's DB transaction, re-reads the
authoritative balance, and throws `INSUFFICIENT_BALANCE` before updating.
Two concurrent workers cannot both reserve the same kobo: the second blocks
on the row lock, then sees the first worker's committed balance.

Reservation lifecycle: `ACTIVE → COMMITTED | RELEASED | EXPIRED`.
Stale ACTIVE reservations are released by `wallets:expire-reservations`
(every minute).

## 5. Transaction state machine (charter §23)

`App\Domain\Transactions\StateMachines\TransactionStateMachine` encodes the
charter diagram exactly:

```
INITIATED → PENDING → PROCESSING → SUCCESS → COMPLETED
                       ↘ AMBIGUOUS → VERIFYING → SUCCESS|FAILED
(any non-terminal) → FAILED
SUCCESS → REVERSED (controlled compensating process)
```

`TransactionService::transition()` re-locks the row, asserts the transition
and appends to `transaction_events` (append-only audit trail) inside the
caller's DB transaction. Terminal states have no outgoing transitions.

## 6. Idempotency (charter §24)

`IdempotencyService` stores per (user, key): request hash (method + path +
sorted body + user id), status, stored response and the transaction
reference.

- first request → `IN_PROGRESS`, proceed;
- retry of a completed request → **original response returned**;
- retry of an in-flight request → `REQUEST_IN_PROGRESS` (409);
- same key, different body → `IDEMPOTENCY_KEY_REUSED` (409).

Enforced on `POST /wallet/fund` and `POST /bills/pay` via the `idempotent`
middleware (header presence) + the service (semantics).

## 7. Provider framework & ambiguity (charter §25–28)

All provider traffic funnels through `ProviderGateway`, which:

- enforces provider status and the **circuit breaker** (per-provider,
  cache-backed: `CLOSED → OPEN → HALF_OPEN`),
- records a `provider_attempts` row per call (audit + reconciliation input),
- classifies outcomes with `OutcomeClassifier`:
  - 2xx + success → `DEFINITIVE_SUCCESS`
  - 4xx → `DEFINITIVE_FAILURE`
  - 5xx / 0xx / timeout / connection reset / unknown → **`AMBIGUOUS`**

**Ambiguous transactions never fail over.** The reservation stays ACTIVE
(funds held), the transaction moves `PROCESSING → AMBIGUOUS → VERIFYING`,
and resolution happens by *verifying the original provider transaction*
(`POST /transactions/{reference}/verify`, the scheduled
`transactions:verify-stale` command, or a provider webhook).

## 8. Transactional outbox (charter §29)

`OutboxService::record()` inserts an `outbox_events` row inside the SAME DB
transaction as the financial change. `OutboxPublisher` (command
`outbox:publish`, scheduled every minute) locks, dispatches a Laravel event
(listeners create customer notifications), marks events `DISPATCHED`, and
retries failures up to 5 attempts before `FAILED`. No event can be lost if a
process dies after commit.

## 9. Webhooks (charter §28)

`POST /api/v1/webhooks/{provider}`:

1. HMAC-SHA256 signature verification (`X-Webhook-Signature` over the raw
   body, per-provider secret),
2. raw payload stored in `provider_webhooks`,
3. idempotency on (provider, event id) — replays return 200 and change
   nothing,
4. state updates are idempotent (terminal transactions are no-ops),
5. audit-logged.

## 10. KYC & risk (charter §33–35)

`kyc_profiles.tier` (0/1/2) drives the limits in `config/ase.php`
(`kyc_tiers`: per-transaction, daily amount, daily count, max wallet
balance). `RiskEngine::assess()` evaluates the limits before any funds are
reserved, records a `risk_assessments` row with the signals, and blocks with
`RISK_BLOCKED` (403). The BVN/NIN submission flow uses a mock verifier
(odd-last-digit = verified) so the tier flow is fully exercisable.

## 11. Concurrency & integrity (charter §39–40)

- `SELECT ... FOR UPDATE` on wallet + transaction rows for every mutation,
- `CHECK` constraints on wallet balances,
- unique constraints: ledger account codes, idempotency (user, key), webhook
  (provider, event id), wallet/user references,
- FKs with explicit delete behaviour,
- append-only tables: `ledger_entries`, `transaction_events`, `audit_logs`
  (no `updated_at`; trigger-backed on PostgreSQL),
- feature tests cover double-spend guards, idempotent replays, webhook
  duplication, and ledger integrity (including the immutability trigger on
  PostgreSQL).

## 12. Roadmap status

| Charter phase | Status in this scaffold |
| --- | --- |
| 1 — Foundation (repo, Laravel, DB, auth, CI) | ✅ done |
| 2 — Financial Core (wallet, ledger, reservations, transactions, idempotency, outbox, provider framework) | ✅ done (mock providers) |
| 3 — Funding (real payment providers, webhooks, reconciliation) | ⚙️ framework + mock; plug in Paystack/Flutterwave as `PaymentProviderInterface` implementations |
| 4 — Bills (real bill providers) | ⚙️ framework + mock; implement `BillProviderInterface` per provider |
| 5 — Betting | 🕗 catalog/risk hooks ready; provider pending |
| 6 — KYC & Risk | ⚙️ tiers/limits/risk engine live; real BVN/NIN verification pending |
| 7 — Reconciliation | ✅ engine + command; provider record feeds via `ReconciliationProviderInterface` |
| 8 — Mobile | 🕗 API contract ready (OpenAPI) |
| 9 — Production hardening | 🕗 Docker/CI ready; load/pen testing pending |
