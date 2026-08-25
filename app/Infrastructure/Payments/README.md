Adapters for external payment/rail integrations (bank transfer APIs, funding
rails).

- `Wema/` — Wema Bank (ALAT) Developer API client and providers: wallet
  funding via payment requests (bank transfer IN) and payouts (bank
  transfer OUT). See `docs/PROVIDERS.md`.
- `Monnify/` — Monnify API client and providers: wallet funding via
  checkout (bank transfer / USSD / card) and payouts via single-transfer
  disbursements. See `docs/PROVIDERS.md`.

The mock provider (`MockPaymentProvider`, `MockPayoutProvider`,
`MockBillProvider`) is used for local development and automated tests only.

Real provider adapters implement the domain contracts in
`app/Domain/Providers/Contracts` and `app/Domain/Payments/Contracts` and are
registered by name in `config/ase.php`; they are resolved through
`app/Infrastructure/Providers/ProviderFactory.php`.
