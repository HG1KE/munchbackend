<?php

namespace App\Jobs;

use App\Models\MunchOrderWebhookOutbox;
use App\Services\MunchOrdersWebhook\MunchOrderWebhookHttpClient;
use App\Services\MunchOrdersWebhook\MunchOrderWebhookOutboxPayloadRefresher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class DeliverMunchOrderWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function __construct(
        public int $outboxId,
    ) {
    }

    public function handle(MunchOrderWebhookHttpClient $client, MunchOrderWebhookOutboxPayloadRefresher $payloadRefresher): void
    {
        $outbox = MunchOrderWebhookOutbox::query()->find($this->outboxId);
        if (! $outbox) {
            return;
        }

        if ($outbox->status === MunchOrderWebhookOutbox::STATUS_DELIVERED) {
            return;
        }

        if ($outbox->status === MunchOrderWebhookOutbox::STATUS_FAILED) {
            return;
        }

        $outbox = $payloadRefresher->refresh($outbox);

        $attemptNumber = $outbox->attempt_count + 1;
        $outbox->attempt_count = $attemptNumber;
        $outbox->last_attempted_at = now();
        $outbox->save();

        $payload = is_array($outbox->payload) ? $outbox->payload : [];
        $result = $client->post($outbox, $payload);
        $client->logAttempt($outbox, $result, $attemptNumber);

        $outbox->last_http_status = $result['http_status'];
        $outbox->last_error = $result['error'];

        if ($result['success']) {
            $outbox->status = MunchOrderWebhookOutbox::STATUS_DELIVERED;
            $outbox->delivered_at = now();
            $outbox->save();

            return;
        }

        $outbox->save();

        if (! $result['retryable']) {
            $outbox->status = MunchOrderWebhookOutbox::STATUS_FAILED;
            $outbox->save();
            $this->fail(new RuntimeException((string) ($result['error'] ?? 'permanent_webhook_failure')));

            return;
        }

        throw new RuntimeException((string) ($result['error'] ?? 'retryable_webhook_failure'));
    }

    public function failed(Throwable $e): void
    {
        $outbox = MunchOrderWebhookOutbox::query()->find($this->outboxId);
        if (! $outbox || $outbox->status === MunchOrderWebhookOutbox::STATUS_DELIVERED) {
            return;
        }

        if ($outbox->status !== MunchOrderWebhookOutbox::STATUS_FAILED) {
            $outbox->status = MunchOrderWebhookOutbox::STATUS_FAILED;
            $outbox->last_error = $e->getMessage();
            $outbox->save();
        }
    }
}
