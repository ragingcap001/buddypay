# Provider Integrations

This document covers the external payment rails integrated into the Aṣẹ
platform and how they map onto the internal financial core.

## How providers fit the platform

All provider traffic funnels through `App\Domain\Providers\Services\
ProviderGateway`, which:

1. enforces provider status and the circuit breaker,
2. records a `provider_attempts` row for every call,
3. classifies the outcome (`DEFINITIVE_SUCCESS` | `DEFINITIVE_FAILURE` |
   `AMBIGUOUS`),
4. feeds the circuit breaker.

Ambiguous outcomes (timeouts, connection resets, 5xx) **never** trigger
automatic failover — the transaction moves to `AMBIGUOUS → VERIFYING` and is
settled by the provider webhook, `POST /api/v1/transactions/{reference}/verify`,
or the `transactions:verify-stale` sweep. Provider-specific code lives only
in `app/Infrastructure/Providers/` and is wired by name in
`config/ase.php`.

### Wallet funding (bank transfer IN)

| Step | What happens |
| --- | --- |
| 1 | `POST /api/v1/wallet/fund` with `amount`, `method`, `provider` (idempotent, PIN required). |
| 2 | A `WALLET_FUNDING` transaction is created (`PROCESSING`) and the provider is charged. |
| 3 | The provider returns deposit instructions (virtual account / checkout URL). Because the money has not moved, the outcome is `AMBIGUOUS` — the transaction enters `VERIFYING` and the instructions are stored in `transaction.metadata.payment_details` and returned in the response. |
| 4 | The customer transfers to the virtual account (or pays the checkout URL). |
| 5 | The provider webhook (or the verify endpoint / stale sweep) settles the transaction: `SUCCESS → COMPLETED` and the wallet is credited (`DR FUNDING_RECEIVABLE / CR WALLET:{user}`). |

### Payouts (bank transfer OUT)

| Step | What happens |
| --- | --- |
| 1 | `POST /api/v1/wallet/payout` with `amount`, `bank_code`, `account_number`, `account_name`, optional `narration` / `provider` (idempotent, PIN required). |
| 2 | Risk check (KYC tier limits) then an atomic initiation: wallet funds are **reserved** (amount + fee) and a `BANK_TRANSFER` transaction is created (`PROCESSING`). |
| 3 | The provider payout is initiated (name enquiry first, where the rail supports it). NIP settlement is asynchronous, so the outcome is usually `AMBIGUOUS` — `VERIFYING`, reservation held. |
| 4 | Provider webhook / verify endpoint / stale sweep settles it: `COMPLETED` commits the reservation (`DR WALLET:{user}` / `CR PROVIDER_PAYABLE` + fee); `FAILED` releases the reservation back to the user. |
| 5 | If the reservation TTL lapses before confirmation, `wallets:expire-reservations` releases the funds and reconciliation flags the transaction. |

---

## Wema (ALAT)

- Portal: <https://wema-alatdev-apimgt.developer.azure-api.net/>
- Base URL (test): `https://wema-alatdev-apimgt.developer.azure-api.net`
  (production URL issued at onboarding)
- Auth: subscription key in the `Api-Key` header on every request.
- Response envelope: `{ result, errorMessage, errorMessages[], hasError, timeGenerated }`.

### Endpoints used

| Purpose | Method + path |
| --- | --- |
| Create payment request (funding — virtual account for an exact amount) | `POST /payments/v1/paymentrequests` |
| Payment request status | `GET /payments/v1/paymentrequests/{reference}` |
| NIP name enquiry | `GET /name-enquiry/v1/name-enquiry/{bankCode}/{accountNumber}` |
| Payout (bank transfer out) | `POST /payouts/v2/payouts` |
| Payout status | `GET /payouts/v2/payouts/{reference}` |

### Mapping

- **Funding**: `charge()` creates a payment request using the platform
  transaction reference as the Wema reference. Wema returns
  `result.virtualAccount` (+ `virtualBank`) — surfaced to the client via
  `metadata.payment_details`. Settlement: Wema callback (below) or
  `verify()` against the payment-request status endpoint.
- **Payouts**: `payout()` first runs a NIP name enquiry (a beneficiary that
  cannot be confirmed fails the transaction *before* any transfer is
  initiated) and sends the name Wema itself reports. Settlement: callback or
  `verify()` against the payout status endpoint.

### Webhook contract

Wema posts the ALAT callback model to `WEMA_WEBHOOK_URL` (configure it as
`https://<public-host>/api/v1/webhooks/wema`):

```json
{
  "title": "Payout Notification",
  "message": "Transfer successful",
  "data": {
    "status": "PENDING | SUCCESSFUL | FAILED",
    "message": "Transfer successful",
    "narration": "Transfer",
    "transactionReference": "<platform transaction reference>",
    "platformTransactionReference": "<Wema reference>"
  }
}
```

- `data.transactionReference` is the platform reference we passed on the
  payment request / payout — it is the join key.
- `SUCCESSFUL` → success, `FAILED` → failure, `PENDING` → acknowledged and
  ignored (no state change).
- Inbound signatures are verified as HMAC-SHA256 of the raw body in
  `X-Webhook-Signature`, keyed by `ASE_WEBHOOK_SECRET_WEMA`.

### Outcome classification

- 4xx responses (e.g. `Invalid Channel ID`, `Insufficient balance on source
  account`) → `DEFINITIVE_FAILURE` (the request was rejected before
  initiation).
- 5xx, timeouts, connection resets, 2xx-with-`hasError` → `AMBIGUOUS`
  (verify; never blindly fail over).

### Configuration

```env
WEMA_BASE_URL=https://wema-alatdev-apimgt.developer.azure-api.net
WEMA_API_KEY=...
WEMA_WEBHOOK_URL=https://api.example.com/api/v1/webhooks/wema
ASE_WEBHOOK_SECRET_WEMA=...
ASE_DEFAULT_PAYOUT_PROVIDER=wema
```

---

## Monnify

*(scaffold in progress — see the Monnify section added alongside the
`app/Infrastructure/Providers/Monnify/` adapters)*
