<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Paystack\PaystackFulfillmentService;
use App\Services\Paystack\PaystackFulfillmentSource;
use App\Services\Paystack\PaystackWebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaystackWebhookController extends Controller
{
    public function __construct(
        private readonly PaystackWebhookSignatureVerifier $signatureVerifier,
        private readonly PaystackFulfillmentService $fulfillment,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        if (! $this->signatureVerifier->isValid($rawPayload, is_string($signature) ? $signature : null)) {
            Log::warning('paystack.webhook_invalid_signature', [
                'has_signature' => is_string($signature) && $signature !== '',
                'secret_configured' => $this->signatureVerifier->hasSecretConfigured(),
            ]);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            Log::warning('paystack.webhook_invalid_payload');

            return response()->json(['message' => 'Invalid payload.'], 400);
        }

        $event = (string) ($payload['event'] ?? '');
        $reference = trim((string) ($payload['data']['reference'] ?? ''));

        Log::info('paystack.webhook_received', [
            'event' => $event,
            'reference' => $reference !== '' ? $reference : null,
        ]);

        if ($event !== 'charge.success') {
            Log::info('paystack.webhook_ignored', [
                'event' => $event,
                'reference' => $reference !== '' ? $reference : null,
            ]);

            return response()->json(['status' => 'ignored', 'event' => $event]);
        }

        if ($reference === '') {
            Log::warning('paystack.webhook_missing_reference', ['event' => $event]);

            return response()->json(['message' => 'Missing transaction reference.'], 422);
        }

        $metadata = $payload['data']['metadata'] ?? [];
        if (is_object($metadata)) {
            $metadata = (array) $metadata;
        }
        $paymentRequestId = is_array($metadata) ? ($metadata['payment_request_id'] ?? null) : null;
        $paymentRequestId = is_string($paymentRequestId) && $paymentRequestId !== '' ? $paymentRequestId : null;

        try {
            $result = $this->fulfillment->fulfillPaidCharge(
                $reference,
                PaystackFulfillmentSource::WEBHOOK,
                $paymentRequestId,
            );
        } catch (Throwable $exception) {
            Log::error('paystack.webhook_failed', [
                'event' => $event,
                'reference' => $reference,
                'payment_request_id' => $paymentRequestId,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $outcome = (string) ($result['outcome'] ?? 'failed');
        $orderId = $result['order_id'] ?? null;
        $orderPlaced = (bool) ($result['order_placed'] ?? false);

        Log::info('paystack.webhook_processed', [
            'event' => $event,
            'reference' => $reference,
            'payment_request_id' => $paymentRequestId ?? ($result['payment_request']->id ?? null),
            'outcome' => $outcome,
            'order_placed' => $orderPlaced,
            'order_id' => $orderId,
        ]);

        return response()->json([
            'status' => 'processed',
            'outcome' => $outcome,
            'reference' => $reference,
            'order_placed' => $orderPlaced,
            'order_id' => $orderId,
        ]);
    }
}
