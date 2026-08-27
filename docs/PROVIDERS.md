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

- Docs: <https://developers.monnify.com/>
- Base URL: `https://sandbox.monnify.com` (sandbox) / `https://api.monnify.com` (live)
- Auth: OAuth 2.0 client-credentials Bearer token —
  `POST /api/v2/oauth/token` with `api_key`, `contract_code`, `secret_key`.
  Tokens are short-lived and cached in the cache store.
- Amounts: NGN major units with two decimals (e.g. `1000.00`); the client
  converts from the platform's integer kobo with pure integer math.

### Endpoints used

| Purpose | Method + path |
| --- | --- |
| OAuth token | `POST /api/v2/oauth/token` |
| Initialize one-time payment (funding: bank transfer / card / USSD) | `POST /api/v2/charges/transactions` |
| Collection transaction status | `GET /api/v2/charges/transactions/{reference}` |
| Reserve customer virtual account (persistent, per customer) | `POST /api/v2/bank-transfer/reserved-accounts` |
| Reserved account status | `GET /api/v2/bank-transfer/reserved-accounts/{accountReference}` |
| Single transfer (payout, async) | `POST /api/v2/disbursements/single` |
| Authorize single transfer (MFA OTP) | `POST /api/v2/disbursements/single/validate-otp` |
| Resend MFA OTP | `POST /api/v2/disbursements/single/resend-otp` |
| Single transfer status | `GET /api/v2/disbursements/single/summary?reference={reference}` |
| NIP name enquiry | `GET /api/v2/transfer/name-enquiry/{bankCode}/{accountNumber}` |
| Disbursement wallet balance | `GET /api/v2/disbursements/wallet-balance?accountNumber={account}` |

### Mapping

- **Funding**: `charge()` initializes a one-time payment whose
  `paymentReference` is the platform transaction reference. Monnify returns
  a `paymentUrl` and (for bank transfer) a virtual account — surfaced to the
  client via `metadata.payment_details`. Settlement: `SUCCESSFUL_TRANSACTION`
  webhook (matched on `paymentReference`) or `verify()`.
  The Customer Reserved Account API is available on the client for the
  persistent-per-customer virtual account product; the one-time flow keeps a
  1:1 mapping to platform transactions.
- **Payouts**: `payout()` initiates an **async** single transfer funded from
  the platform's Monnify disbursement wallet (`MONNIFY_SOURCE_ACCOUNT`).
  Monnify MFA is **enabled by default** on disbursement accounts, so the
  usual response is `PENDING_AUTHORIZATION` → `AMBIGUOUS`; the operator then
  runs:

  ```bash
  php artisan payouts:authorize {transaction-reference} {otp}
  ```

  (OTP is emailed to the Monnify account's registered email. A
  `resend-otp` client call exists if it expires.) Final status also arrives
  via `SUCCESSFUL_DISBURSEMENT` / `FAILED_DISBURSEMENT` webhooks or
  `verify()`.

### Webhook contract

Monnify POSTs `{ eventType, eventData: { ... } }` to the dashboard-configured
webhook URL (point it at `https://<public-host>/api/v1/webhooks/monnify`):

- `SUCCESSFUL_TRANSACTION` → funding success (`eventData.paymentReference`
  is the platform reference; `eventData.transactionReference` is Monnify's).
- `UNSUCCESSFUL_TRANSACTION` → funding failure.
- `SUCCESSFUL_DISBURSEMENT` → payout success (`eventData.reference` is the
  platform reference — we pass it as the transfer reference).
- `FAILED_DISBURSEMENT` / `UNSUCCESSFUL_DISBURSEMENT` → payout failure.

**Signature verification**: Monnify signs the raw body with the **client
secret key** using HMAC-SHA512 and sends it in the `monnify-signature`
header — **production only** (sandbox notifications are unsigned). Set
`MONNIFY_SECRET_KEY` to the same value as the dashboard's secret key; the
webhook receiver rejects unsigned/mis-signed deliveries when a secret is
configured. Also whitelist Monnify's IP (`35.242.133.146`) at the edge.

### Prerequisites (live)

- Enable **Disbursements** on the Monnify account (Settings → Preferences)
  and whitelist the egress IP address (unwhitelisted payout requests fail
  with `D06`).
- Fund the disbursement wallet (`MONNIFY_SOURCE_ACCOUNT`) — payouts are
  settled from it.

### Configuration

```env
MONNIFY_BASE_URL=https://sandbox.monnify.com
MONNIFY_API_KEY=...
MONNIFY_SECRET_KEY=...
MONNIFY_CONTRACT_CODE=...
MONNIFY_SOURCE_ACCOUNT=...
```

---

## Kuda (Business API v2.1) — bills

- Docs: https://docs.kuda.com/ + `business-support.kuda.com` (Business API)
- Base URL (UAT): `https://kuda-openapi-uat.kudabank.com/v2.1` · Production: `https://kuda-openapi.kuda.com/v2.1`
- Auth: `POST {base}/Account/GetToken` with `{ email, apiKey }` returns a **raw JWT** (not JSON), sent as `Authorization: Bearer <jwt>`. The JWT `exp` is decoded and the token cached until shortly before expiry.
- Envelope: one endpoint for all operations — `{ serviceType, requestRef, data }`.

### Bill operations

| `serviceType` | Purpose |
| --- | --- |
| `GET_BILLERS_BY_TYPE` | Billers + purchasable `billItems` (each with a `kudaIdentifier`) for a category |
| `VERIFY_BILL_CUSTOMER` | Validate a customer reference (phone / meter / card) against a bill item |
| `ADMIN_PURCHASE_BILL` | Execute the bill payment from the business account |
| `BILL_TSQ` | Bill status query (final outcome + PIN/token) |

### Categories & the client flow

Supported bill types map: `AIRTIME`→`airtime`, `DATA`→`internet data`, `BETTING`→`betting` (plus `electricity`, `cabletv`).

1. **Discover** — `GET /api/v1/bills/kuda/catalog?category=airtime|data|betting` returns Kuda's live catalog (pass-through of `GET_BILLERS_BY_TYPE`). Each purchasable item carries a `kudaIdentifier`.
2. **Pay** — `POST /api/v1/bills/pay` with `provider: "kuda"` and the chosen item's identifier as `biller` (plus optional `customer_identifier` / `customer_name`). For airtime only, when no `biller` is supplied the network is inferred from the phone prefix and matched against the catalog; data bundles and bookmakers always require an explicit `biller`.
3. **Settle** — Kuda bill responses are almost never final on the wire (`K00` = received, `K12` = aggregator pending), so the transaction enters `VERIFYING` with the wallet reservation held. Final status comes from the `Bill.Transaction` webhook (which triggers a `BILL_TSQ`) or `POST /api/v1/transactions/{ref}/verify`.

### References & reconciliation

- `requestRef` must be short/unique/alphanumeric (Kuda guidance), so purchases use a generated `KB{ymdHis}{4alnum}` ref stored on the transaction as `metadata.kuda_request_ref`; Kuda's `BillResponseReference` is stored as `provider_reference`.
- **Webhook auth**: Kuda sends a plaintext `username` header and a **Base64-encoded** `password` header (NOT HTTP Basic Auth). Configure both in the Kuda dashboard and the admin dashboard / env.
- Parse Kuda payloads leniently; the webhook is a notification, not a definitive outcome — always confirm with `BILL_TSQ`. `transactionStatus: 3` = completed, `1` = pending.

### Configuration

```env
KUDA_BASE_URL=https://kuda-openapi-uat.kudabank.com/v2.1
KUDA_API_KEY=...
KUDA_BUSINESS_EMAIL=...
KUDA_WEBHOOK_USERNAME=...
KUDA_WEBHOOK_PASSWORD=...
```
