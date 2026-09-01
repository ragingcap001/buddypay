# Admin Dashboard

The admin dashboard is a same-origin web app served at `/admin` by the same
Laravel process as the API. It manages runtime configuration (provider
credentials, push services) and provides provider health and push-tooling
panels.

## Access

1. **Promote an account** (CLI, once per admin):

   ```bash
   php artisan users:make-admin 08031234567
   ```

   (add `--revoke` to remove the role)

2. **Sign in** at `https://<host>/admin` with that account's phone number
   and password (the same credentials as the API login flow).

Session auth + CSRF; the dashboard API (`/api/v1/admin/*`) is gated by the
`admin` middleware and is **not** part of the sanctum bearer flow.

## Configuration model

Every managed key resolves in this order at runtime:

```
DB override (app_config table, encrypted at rest)
  -> environment variable (WEMA_API_KEY, MONNIFY_*, FIREBASE_*, ...)
  -> built-in default
```

- The **dashboard is the primary control plane**: values saved there are
  used by `WemaClient`, `MonnifyClient` and the webhook receiver
  immediately — no redeploy.
- Env vars remain the fallback (and the way to configure in environments
  without dashboard access, e.g. fresh deploys).
- "Reset to env" deletes the DB override for a group.
- Secrets are encrypted at rest and masked in all API/UI responses (last 4
  chars). To change a secret, type the full new value.

### Managed groups

| Group | Keys | Env fallbacks |
| --- | --- | --- |
| `wema` | base_url, api_key, webhook, webhook_secret | `WEMA_BASE_URL`, `WEMA_API_KEY`, `WEMA_WEBHOOK_URL`, `ASE_WEBHOOK_SECRET_WEMA` |
| `monnify` | base_url, api_key, secret_key, contract_code, source_account | `MONNIFY_*` |
| `firebase` | project_id, service_account (JSON) | `FIREBASE_PROJECT_ID`, `FIREBASE_SERVICE_ACCOUNT` |
| `apple` | team_id, key_id, bundle_id, apns_key | `APPLE_*` |
| `google` | sender_id, android_package | `GOOGLE_*` |

The manifest lives in `config/app_config.php` — add a key there (and a
`config:` pointer if it has an `ase.*` config entry) to make it
dashboard-manageable.

## Push notifications (Firebase / Apple / Google)

**FCM (HTTP v1) is the single push transport.** Android (Google) and iOS
(Apple) devices register the same kind of FCM token; FCM relays iOS
deliveries to APNs. So:

- **Firebase** group: project ID + service account JSON — this is what
  makes push *work* (server-side, self-signed RS256 JWT, no extra
  dependencies).
- **Apple** group: APNs team/key/bundle identifiers — client-side
  identifiers for the iOS app (direct APNs delivery is a later phase).
- **Google** group: FCM sender ID (the Firebase project number) + Android
  package — client-side identifiers for the Android app.

### Client (mobile app) registration

```
POST   /api/v1/notifications/devices   { "platform": "ios|android|web", "token": "<fcm token>", "name": "iPhone" }
GET    /api/v1/notifications/devices
DELETE /api/v1/notifications/devices/{token}
```

### Delivery

`NotificationService::send()` (used by the transaction flows) persists the
notification + LOG delivery row and, when Firebase is configured and the
user has active devices, fans out a PUSH delivery (recorded per-attempt in
`notification_deliveries`). Push failures never break the financial flow.

Admin tools: `POST /api/v1/admin/push/test` (send a test push — to a
specific device token, or to a random registered device) and
`GET /api/v1/admin/push/devices` (device overview).

## Provider health panel

`GET /api/v1/admin/providers` lists every registered provider with status,
circuit-breaker state (CLOSED / OPEN / HALF_OPEN) and 24h attempt counts
(success / failure / ambiguous) from the `provider_attempts` audit table.

## Security notes

- `app_config.value` uses Laravel's `encrypted` cast — values are
  ciphertext in PostgreSQL; they decrypt only in-process.
- The admin role is a plain column gate (`users.role`); there is no
  fine-grained permission system yet (single-admin assumption).
- Rotate webhook/provider secrets in the dashboard if a value is suspected
  compromised; consider a DB audit trail of `app_config` changes (currently
  `updated_by`/`updated_at` only) as usage grows.
