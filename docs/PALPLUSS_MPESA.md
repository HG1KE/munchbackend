# PalPluss M-PESA STK Push (Munch)

Non-BYOC integration: Munch → PalPluss STK → Till **channel** → customer PIN → webhook/verify → exact-once order.

## Environment (secrets — never commit)

```env
PALPLUSS_API_KEY=
PALPLUSS_BASE_URL=https://api.palpluss.com/v1
PALPLUSS_CHANNEL_ID=
```

- **API key** is HTTP Basic username (empty password). This is the only auth credential.
- **Do not** set `PALPLUSS_CREDENTIAL_ID` / Daraja BYOC keys unless PalPluss requires BYOC for your account (we do not).
- **Till** is selected only via `PALPLUSS_CHANNEL_ID` (channel UUID). Never send till number on STK.

## How to get `PALPLUSS_CHANNEL_ID`

1. Log in to [console.palpluss.com](https://console.palpluss.com).
2. Open **Payment Channels** (or equivalent).
3. Create/select a channel with type **Till / TILL_NUMBER** and `shortcode` = your M-Pesa Till.
4. Copy the channel **`id`** (UUID) → `PALPLUSS_CHANNEL_ID`.
5. Optionally mark it **default**.
6. Fund the **service wallet** (STK fees).

Or list via API (never paste the key into chat):

```bash
curl -sS "https://api.palpluss.com/v1/payment-wallet/channels" -u "$PALPLUSS_API_KEY:"
```

After env is set on the server:

```bash
php artisan palpluss:verify-channel
```

Reports `channel_type`, `channel_shortcode`, `channel_till_like`, wallet balance — not secrets.

## Customer API flow

1. `POST /api/v1/payment-mobile` with `payment_method=palpluss` (or `mpesa_stk`) + `phone`
2. Response: `{ checkout_mode: "palpluss_stk", payment_id, transaction_id, status: "pending", message }`
3. UI: “Check your phone and enter your M-PESA PIN”
4. Poll `POST /api/v1/palpluss/verify` or `GET /api/v1/palpluss/status`
5. Webhook: `POST /api/v1/palpluss/webhook` (per-request `callbackUrl`)

STK accepted ≠ paid. Fulfillment always re-queries `GET /transactions/{id}`.

## Ops

- Reconcile: `php artisan palpluss:reconcile-unverified` (scheduled every 5 minutes)
- Paystack remains unchanged

## Security

- Never log API keys or Authorization headers
- Webhooks have no documented signature — verify via PalPluss transaction API + amount/currency/reference checks
