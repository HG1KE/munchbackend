# PalPluss M-PESA STK Push (Munch)

Non-BYOC integration: Munch → PalPluss STK → Till **channel** → customer PIN → webhook/verify → exact-once order.

## Configuration (Admin Payment Settings)

**Source of truth:** Admin → Third Party → Payment Setup → **M-PESA (PalPluss)**

1. Paste PalPluss API key (stored **encrypted**; leave blank on later saves to keep existing key).
2. Click **Load channels** → select a **Till / TILL_NUMBER** channel (shortcode = your M-Pesa Till).
3. Click **Test connection** to verify credentials, Till type, and service wallet.
4. Enable the gateway and **Save**.

Runtime credentials are read from `addon_settings` (`key_name=palpluss`).  
Do **not** put `PALPLUSS_API_KEY` / `PALPLUSS_CHANNEL_ID` in `.env` for normal operation.

Optional non-secret default:

```env
PALPLUSS_BASE_URL=https://api.palpluss.com/v1
```

Legacy env fallbacks exist only if Admin values are empty (migration/bootstrap). Admin always takes precedence.

## Auth model

HTTP Basic: API key as username, empty password. No BYOC / Daraja / `credential_id`.

## Customer API flow

1. `POST /api/v1/payment-mobile` with `payment_method=palpluss` (or `mpesa_stk`) + `phone`
2. Response: `{ checkout_mode: "palpluss_stk", payment_id, transaction_id, status: "pending", message }`
3. Poll `POST /api/v1/palpluss/verify` or `GET /api/v1/palpluss/status`
4. Webhook: `POST /api/v1/palpluss/webhook` — always re-queries `GET /transactions/{id}`

## Ops

```bash
php artisan palpluss:verify-channel
php artisan palpluss:reconcile-unverified
```

Paystack remains unchanged.
