# Munch online order ringing webhook (`order.ringing`)

Outbound webhook from the portal backend when an **online** order first enters the same pending queue that drives branch “new order” alerts (`order_request` / notification sound).

## Trigger

Event name: **`order.ringing`**

Fires **once** per order when it first qualifies for:

- **Online scope** — `Order::onlineOrders()` / `PosOrderTypes::isOnlineOrder()` (website/app/API; not POS family, not dine-in)
- **Schedule** — `notSchedule()`: `delivery_date` is today or earlier
- **Status** — `order_status` in `OnlineOrderStatus::pendingQueueStatuses()` (`pending`, `confirmed`)

Typical path: customer `placeOrder` commits with `order_status = pending`, alongside `SendOnlineOrderPlacementNotificationsJob`.

**Excluded:** Branch POS, dine-in POS, POS delivery, Glovo, Uber, Bolt Food. **Makadara** (`branches.id = 11`) is skipped. Webhook branches use portal branch IDs:

| `branches.id` | Payload `branch` |
|---------------|------------------|
| 1 | Nyali |
| 10 | Bamburi |
| 13 | Mtwapa |
| 14 | Kilimani |
| 11 | *(no webhook — Makadara)* |

No second `order.ringing` when the order later moves to preparing, ready, completed, or cancelled.

## Configuration

Set in environment (never commit real values):

```env
MUNCH_ORDERS_WEBHOOK_URL=
MUNCH_ORDERS_WEBHOOK_AUTH=
```

Optional:

```env
MUNCH_ORDERS_WEBHOOK_TIMEOUT=15
MUNCH_ORDERS_WEBHOOK_CONNECT_TIMEOUT=5
```

Laravel config: `config/munch_orders_webhook.php`.

| Variable | Purpose |
|----------|---------|
| `MUNCH_ORDERS_WEBHOOK_URL` | Full HTTPS URL for the outbound POST |
| `MUNCH_ORDERS_WEBHOOK_AUTH` | Full `Authorization` header value (e.g. `Bearer …`) |

## Payload (minimum)

```json
{
  "event": "order.ringing",
  "order_id": "<portal orders.id>",
  "branch": "<branch name from Branch model>",
  "occurred_at": "<ISO-8601>"
}
```

Optional when present on the order: `status`, `channel`, `customer_name`, `total`.

## Outbox and atomicity

For each **eligible** online ringing order:

1. The `order.ringing` outbox row is inserted **inside the same database transaction** as the order (`MunchOrderRingingWebhookRecorder::recordFromAttributes()` before `DB::commit()` in `placeOrder`).
2. **Unique constraint** on `(event_type, order_id)` — logical key `order.ringing:{order_id}`; duplicate record attempts return the existing row (one logical event per order).

### A. Outbox persistence failure

If the outbox record **cannot** be persisted for an eligible order:

- The database transaction **fails** and rolls back.
- The eligible order is **not** committed.
- No webhook is sent.

Order and outbox event are **atomic** for the placement path.

### B. External webhook delivery failure

After the order and outbox **commit**:

- `DeliverMunchOrderWebhookJob` runs **asynchronously** (`afterResponse()`); the customer HTTP response is not blocked on the external POST.
- External HTTP may fail or time out; the **customer order stays committed**.
- The outbox row remains **durable** (`pending`, then `delivered` or `failed`).
- **Retries** handle transient delivery failures (see below).

Outbox insert failures are **not** swallowed; delivery failures do **not** roll back an already-committed order.

## Async delivery and retries

- `DeliverMunchOrderWebhookJob` is dispatched on the **dedicated Redis queue** `munch-webhooks` (not `QUEUE_CONNECTION=sync`), so HTTP never runs inside PHP-FPM order placement.
- Supervisor must consume: `queue:work redis --queue=munch-webhooks,...`
- After changing webhook `.env` values, run `php artisan config:cache` and **reload PHP-FPM** so web workers see fresh config.
- `DeliverMunchOrderWebhookJob` POSTs JSON to `MUNCH_ORDERS_WEBHOOK_URL` with configured auth.
- Queue retries: about **1, 5, 15, 30 minutes** (`$tries = 5`, backoff `[60, 300, 900, 1800]` seconds).
- **Success:** HTTP 2xx → outbox `delivered`.
- **Retry:** 408, 429, 5xx, connection errors, or **missing `MUNCH_ORDERS_WEBHOOK_URL` / `MUNCH_ORDERS_WEBHOOK_AUTH`** → job retries with outbox left `pending`; same `Idempotency-Key: order.ringing:{order_id}` on each attempt.
- **Permanent failure:** other 4xx (e.g. 401, 403) → outbox `failed`; order unchanged.
- **Re-queue after fixing env or a failed row:** `php artisan munch:orders-webhook-retry --order=<id>` (or `--outbox=<id>`).

## Dry run

Preview payload for an **existing** order (no order mutation, no outbox row, no queue job):

```bash
php artisan munch:orders-webhook-dry-run --order=100001
```

Optional one-shot POST to the configured URL (use only when intentional):

```bash
php artisan munch:orders-webhook-dry-run --order=100001 --send
```

Do not use `--send` in CI or routine checks unless you intend to hit the real endpoint.

## Tests

PHPUnit covers outbox idempotency, scope exclusions, atomicity, and HTTP delivery with **`Http::fake`** — no real webhook URL or production auth in tests, and **no real HTTP** to Grok or production.

```bash
php artisan test tests/Unit/MunchOrderRingingWebhookTest.php tests/Unit/OnlineOrderIdempotencyTest.php
```

Migration (run in each environment before relying on the feature): `2026_09_28_150000_create_munch_order_webhook_outbox_table.php`.
