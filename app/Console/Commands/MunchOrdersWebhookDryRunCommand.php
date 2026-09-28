<?php

namespace App\Console\Commands;

use App\Model\Order;
use App\Services\MunchOrdersWebhook\MunchOrderWebhookHttpClient;
use App\Services\MunchOrdersWebhook\MunchOrderWebhookPayloadBuilder;
use Illuminate\Console\Command;

class MunchOrdersWebhookDryRunCommand extends Command
{
    protected $signature = 'munch:orders-webhook-dry-run
                            {--order= : Existing portal order ID to preview payload for}
                            {--send : Actually POST to the configured webhook URL (use with care)}';

    protected $description = 'Preview (or optionally send) the order.ringing webhook payload for an existing order';

    public function handle(
        MunchOrderWebhookPayloadBuilder $payloadBuilder,
        MunchOrderWebhookHttpClient $client,
    ): int {
        $orderId = (int) $this->option('order');
        if ($orderId < 1) {
            $this->error('Provide --order=<id> for an existing order.');

            return self::FAILURE;
        }

        $order = Order::query()->with(['branch', 'customer'])->find($orderId);
        if (! $order) {
            $this->error("Order {$orderId} was not found.");

            return self::FAILURE;
        }

        $payload = $payloadBuilder->build($order);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->line($json === false ? '{}' : $json);

        if (! $this->option('send')) {
            $this->info('Dry run only — no outbox row, no queue job, no HTTP request (pass --send to POST).');

            return self::SUCCESS;
        }

        if ((string) config('munch_orders_webhook.url') === '') {
            $this->error('MUNCH_ORDERS_WEBHOOK_URL is not configured.');

            return self::FAILURE;
        }

        $stub = new \App\Models\MunchOrderWebhookOutbox([
            'event_key' => \App\Models\MunchOrderWebhookOutbox::eventKeyForOrder($orderId),
            'order_id' => $orderId,
            'payload' => $payload,
        ]);

        $result = $client->post($stub, $payload);
        if ($result['success']) {
            $this->info('Webhook POST succeeded (HTTP '.($result['http_status'] ?? '?').').');

            return self::SUCCESS;
        }

        $this->error('Webhook POST failed: '.($result['error'] ?? 'unknown'));

        return self::FAILURE;
    }
}
