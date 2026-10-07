<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PalPlussPaymentAttempt;
use App\Services\PalPluss\PalPlussFulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PalPluss per-request callbackUrl handler.
 *
 * Official docs do not define a webhook signature. We parse the payload,
 * persist it, acknowledge 200, and fulfill only after GET /transactions/{id}.
 */
class PalPlussWebhookController extends Controller
{
    public function __construct(
        private readonly PalPlussFulfillmentService $fulfillment,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            Log::warning('palpluss.webhook_invalid_json');

            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        if (($payload['event'] ?? null) !== 'transaction.updated') {
            Log::info('palpluss.webhook_ignored_event', ['event' => $payload['event'] ?? null]);

            return response()->json(['status' => 'ignored']);
        }

        $eventType = (string) ($payload['event_type'] ?? '');
        $transaction = $payload['transaction'] ?? null;
        if (! is_array($transaction) || ! is_string($transaction['id'] ?? null)) {
            return response()->json(['message' => 'Missing transaction.'], 422);
        }

        $transactionId = (string) $transaction['id'];

        $attempt = PalPlussPaymentAttempt::query()
            ->where('transaction_id', $transactionId)
            ->first();

        if ($attempt !== null) {
            $attempt->last_webhook_payload = $payload;
            $attempt->save();
        }

        Log::info('palpluss.webhook_received', [
            'event_type' => $eventType,
            'transaction_id' => $transactionId,
            'status' => $transaction['status'] ?? null,
            'attempt_found' => $attempt !== null,
        ]);

        if ($eventType !== 'transaction.success'
            && strtoupper((string) ($transaction['status'] ?? '')) !== 'SUCCESS') {
            if ($attempt !== null) {
                // Soft-update terminal failure statuses from webhook; fulfillment also re-queries.
                $status = strtoupper((string) ($transaction['status'] ?? ''));
                $mapped = match ($status) {
                    'FAILED' => PalPlussPaymentAttempt::STATUS_FAILED,
                    'CANCELLED' => PalPlussPaymentAttempt::STATUS_CANCELLED,
                    'EXPIRED' => PalPlussPaymentAttempt::STATUS_EXPIRED,
                    'REVERSED' => PalPlussPaymentAttempt::STATUS_REVERSED,
                    default => null,
                };
                if ($mapped !== null && ! $attempt->isSuccessful()) {
                    $attempt->status = $mapped;
                    $attempt->terminal_at = $attempt->terminal_at ?? now();
                    $attempt->result_code = isset($transaction['result_code']) ? (string) $transaction['result_code'] : $attempt->result_code;
                    $attempt->result_desc = isset($transaction['result_desc']) ? (string) $transaction['result_desc'] : $attempt->result_desc;
                    $attempt->save();
                }
            }

            return response()->json([
                'status' => 'acknowledged',
                'event_type' => $eventType,
                'transaction_id' => $transactionId,
            ]);
        }

        try {
            $result = $this->fulfillment->fulfill(
                $transactionId,
                PalPlussFulfillmentService::SOURCE_WEBHOOK,
                $attempt?->payment_request_id
            );
        } catch (Throwable $e) {
            Log::error('palpluss.webhook_fulfillment_failed', [
                'transaction_id' => $transactionId,
                'message' => $e->getMessage(),
            ]);

            // Still 200 so PalPluss does not endlessly retry on our bugs;
            // reconciliation will pick up SUCCESS orphans.
            return response()->json([
                'status' => 'accepted',
                'outcome' => 'error',
                'transaction_id' => $transactionId,
            ]);
        }

        return response()->json([
            'status' => 'processed',
            'outcome' => $result['outcome'],
            'transaction_id' => $transactionId,
            'order_placed' => $result['order_placed'],
            'order_id' => $result['order_id'],
        ]);
    }
}
