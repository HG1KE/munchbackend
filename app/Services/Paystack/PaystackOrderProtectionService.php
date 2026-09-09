<?php

namespace App\Services\Paystack;

use App\Exceptions\PaystackException;
use App\Http\Controllers\Api\V1\OrderController;
use App\Model\Order;
use App\Models\PaymentRequest;
use App\Services\PaystackService;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PaystackOrderProtectionService
{
    private const AMOUNT_TOLERANCE_MAJOR = 1.0;

    public function __construct(
        private readonly PaystackService $paystack,
    ) {
    }

    public function findExistingOrderId(string $transactionReference): ?int
    {
        $reference = trim($transactionReference);
        if ($reference === '') {
            return null;
        }

        $order = Order::query()
            ->where('payment_method', 'paystack')
            ->where('transaction_reference', $reference)
            ->orderByDesc('id')
            ->first();

        return $order ? (int) $order->id : null;
    }

    /**
     * @throws PaystackException
     */
    public function assertPaystackChargeVerified(string $transactionReference, ?string $paymentRequestId = null): PaymentRequest
    {
        $reference = trim($transactionReference);
        if ($reference === '') {
            throw new PaystackException('Paystack transaction reference is required.');
        }

        if (! $this->paystack->isConfigured()) {
            throw new PaystackException('Paystack is not configured.');
        }

        $paymentRequest = $this->resolvePaymentRequest($reference, $paymentRequestId);

        if ($paymentRequest !== null && (int) $paymentRequest->is_paid === 1) {
            return $paymentRequest;
        }

        $paymentDetails = $this->paystack->verifyTransaction($reference);
        $this->assertGatewaySuccess($paymentDetails);

        if ($paymentRequest === null) {
            Log::warning('paystack.protection_no_payment_request', ['reference' => $reference]);

            return new PaymentRequest([
                'transaction_id' => $reference,
                'payment_method' => 'paystack',
                'is_paid' => 1,
            ]);
        }

        $this->assertPaystackAmountMatches($paymentRequest, $paymentDetails);

        $metadata = $paymentDetails['data']['metadata'] ?? [];
        if (is_object($metadata)) {
            $metadata = (array) $metadata;
        }
        $attributeId = $metadata['attribute_id'] ?? $paymentRequest->attribute_id;

        PaymentRequest::query()
            ->where('id', $paymentRequest->id)
            ->update([
                'payment_method' => 'paystack',
                'is_paid' => 1,
                'transaction_id' => $reference,
                'attribute_id' => $attributeId ?? $paymentRequest->attribute_id,
            ]);

        return PaymentRequest::query()->find($paymentRequest->id) ?? $paymentRequest;
    }

    /**
     * @param  array<string, mixed>|null  $paymentDetails
     * @return array{
     *     order_id: ?int,
     *     order_placed: bool,
     *     placement_status: string,
     *     placement_error: ?array<string, mixed>
     * }
     */
    public function completeAfterVerify(PaymentRequest $paymentRequest, ?array $paymentDetails = null): array
    {
        if ($paymentRequest->attribute !== 'order') {
            return $this->buildResult(null, false, PaymentRequest::PLACEMENT_PENDING, null);
        }

        $paymentRequestId = $paymentRequest->id;

        return DB::transaction(function () use ($paymentRequestId, $paymentDetails) {
            $paymentRequest = PaymentRequest::query()
                ->whereKey($paymentRequestId)
                ->lockForUpdate()
                ->first();

            if ($paymentRequest === null) {
                return $this->buildResult(null, false, PaymentRequest::PLACEMENT_PENDING, null);
            }

            $reference = trim((string) ($paymentRequest->transaction_id ?? ''));

            $existingOrderId = $paymentRequest->placed_order_id
                ? (int) $paymentRequest->placed_order_id
                : ($reference !== '' ? $this->findExistingOrderId($reference) : null);

            if ($existingOrderId !== null) {
                $this->markPlacementSuccess($paymentRequest, $existingOrderId);

                return $this->buildResult($existingOrderId, true, PaymentRequest::PLACEMENT_PLACED, null);
            }

            if ((int) $paymentRequest->is_paid !== 1) {
                Log::warning('paystack.protection_placement_skipped_unpaid', [
                    'payment_id' => $paymentRequest->id,
                    'reference' => $reference,
                ]);

                return $this->buildResult(
                    null,
                    false,
                    (string) ($paymentRequest->placement_status ?? PaymentRequest::PLACEMENT_PENDING),
                    null
                );
            }

            try {
                $paymentDetails = $paymentDetails ?? $this->paystack->verifyTransaction($reference);
                $this->assertGatewaySuccess($paymentDetails);
                $this->assertPaystackAmountMatches($paymentRequest, $paymentDetails);
            } catch (PaystackException $exception) {
                $error = $this->buildPlacementError(
                    code: 'paystack_amount_validation_failed',
                    message: $exception->getMessage(),
                    httpStatus: 402,
                    source: 'paystack_verify'
                );
                $this->markPlacementFailed($paymentRequest, $error);

                Log::warning('paystack.protection_amount_validation_failed', [
                    'payment_id' => $paymentRequest->id,
                    'reference' => $reference,
                    'message' => $exception->getMessage(),
                ]);

                return $this->buildResult(null, false, PaymentRequest::PLACEMENT_FAILED, $error);
            }

            $draft = $this->resolvePlaceOrderDraft($paymentRequest);
            if ($draft === null) {
                $error = $this->buildPlacementError(
                    code: 'missing_place_order_draft',
                    message: 'Checkout draft is missing on the payment session.',
                    httpStatus: 422,
                    source: 'payment_request'
                );
                $this->markPlacementFailed($paymentRequest, $error, terminal: true);

                Log::critical('paystack.paid_without_order', [
                    'payment_id' => $paymentRequest->id,
                    'reference' => $reference,
                    'payer_id' => $paymentRequest->payer_id,
                    'payment_amount' => (float) $paymentRequest->payment_amount,
                    'placement_error' => $error,
                    'stage' => 'missing_place_order_draft',
                ]);

                return $this->buildResult(null, false, PaymentRequest::PLACEMENT_FAILED_TERMINAL, $error);
            }

            $additional = $this->decodeAdditionalData($paymentRequest->additional_data);
            $isGuest = (int) ($additional['checkout_is_guest'] ?? 0);
            $customerId = $additional['checkout_customer_id'] ?? $paymentRequest->payer_id;
            $guestId = $additional['checkout_guest_id'] ?? ($isGuest ? $customerId : null);

            $payload = $this->applyFrozenCouponSnapshot($draft, $additional);
            $payload = array_merge($payload, [
                'payment_method' => 'paystack',
                'transaction_reference' => $reference,
            ]);

            if ($isGuest && $guestId !== null && $guestId !== '') {
                $payload['guest_id'] = $guestId;
                $payload['is_guest'] = 1;
            }

            $this->markPlacementAttempted($paymentRequest);

            try {
                $response = $this->dispatchPlaceOrder(
                    $payload,
                    $isGuest ? null : (int) $customerId,
                    (string) $paymentRequest->id
                );
                $orderId = $this->extractOrderIdFromResponse($response);

                if ($orderId !== null) {
                    $this->markPlacementSuccess($paymentRequest, $orderId);
                    Log::info('paystack.protection_order_placed', [
                        'payment_id' => $paymentRequest->id,
                        'reference' => $reference,
                        'order_id' => $orderId,
                    ]);

                    return $this->buildResult($orderId, true, PaymentRequest::PLACEMENT_PLACED, null);
                }

                $error = $this->buildPlacementErrorFromResponse($response);
                $this->markPlacementFailed($paymentRequest, $error);

                Log::critical('paystack.paid_without_order', [
                    'payment_id' => $paymentRequest->id,
                    'reference' => $reference,
                    'payer_id' => $paymentRequest->payer_id,
                    'payment_amount' => (float) $paymentRequest->payment_amount,
                    'http_status' => $response->getStatusCode(),
                    'placement_error' => $error,
                    'stage' => 'order_place_rejected',
                ]);

                return $this->buildResult(null, false, PaymentRequest::PLACEMENT_FAILED, $error);
            } catch (Throwable $exception) {
                $error = $this->buildPlacementError(
                    code: 'placement_exception',
                    message: $exception->getMessage(),
                    httpStatus: 500,
                    source: 'order_place'
                );
                $this->markPlacementFailed($paymentRequest, $error);

                Log::critical('paystack.paid_without_order', [
                    'payment_id' => $paymentRequest->id,
                    'reference' => $reference,
                    'payer_id' => $paymentRequest->payer_id,
                    'payment_amount' => (float) $paymentRequest->payment_amount,
                    'message' => $exception->getMessage(),
                    'stage' => 'order_place_exception',
                ]);

                return $this->buildResult(null, false, PaymentRequest::PLACEMENT_FAILED, $error);
            }
        });
    }

    public function placeOrderFromPaymentRequest(PaymentRequest $paymentRequest): ?int
    {
        $result = $this->completeAfterVerify($paymentRequest);

        return $result['order_id'];
    }

    public function placedOrderIdFromSession(PaymentRequest $paymentRequest): ?int
    {
        if ($paymentRequest->placed_order_id) {
            return (int) $paymentRequest->placed_order_id;
        }

        $additional = $this->decodeAdditionalData($paymentRequest->additional_data);
        $orderId = $additional['placed_order_id'] ?? null;

        return is_numeric($orderId) ? (int) $orderId : null;
    }

    /**
     * @param  array<string, mixed>  $paymentDetails
     *
     * @throws PaystackException
     */
    public function assertPaystackAmountMatches(PaymentRequest $paymentRequest, array $paymentDetails): void
    {
        $gatewayAmountMinor = (int) ($paymentDetails['data']['amount'] ?? 0);
        $expectedMinor = (int) round(((float) $paymentRequest->payment_amount) * 100);
        $differenceMajor = abs($gatewayAmountMinor - $expectedMinor) / 100;

        if ($differenceMajor > self::AMOUNT_TOLERANCE_MAJOR) {
            throw new PaystackException(sprintf(
                'Paystack amount mismatch. Expected %.2f, received %.2f.',
                (float) $paymentRequest->payment_amount,
                $gatewayAmountMinor / 100
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $additional
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function applyFrozenCouponSnapshot(array $draft, array $additional): array
    {
        $snapshot = $additional['coupon_snapshot'] ?? null;
        if (! is_array($snapshot)) {
            return $draft;
        }

        if (! empty($snapshot['code'])) {
            $draft['coupon_code'] = $snapshot['code'];
        }

        if (array_key_exists('discount_amount', $snapshot)) {
            $draft['coupon_discount_amount'] = $snapshot['discount_amount'];
        }

        return $draft;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePlaceOrderDraft(PaymentRequest $paymentRequest): ?array
    {
        $draft = $paymentRequest->place_order_draft;
        if (is_array($draft) && $draft !== []) {
            return $draft;
        }

        $additional = $this->decodeAdditionalData($paymentRequest->additional_data);
        $legacyDraft = $additional['place_order_draft'] ?? null;

        return is_array($legacyDraft) && $legacyDraft !== [] ? $legacyDraft : null;
    }

    /**
     * @param  array<string, mixed>  $paymentDetails
     *
     * @throws PaystackException
     */
    private function assertGatewaySuccess(array $paymentDetails): void
    {
        $successful = ($paymentDetails['status'] ?? false) === true
            && ($paymentDetails['data']['status'] ?? '') === 'success';

        if (! $successful) {
            throw new PaystackException('Paystack payment is not successful.');
        }
    }

    private function resolvePaymentRequest(string $reference, ?string $paymentRequestId): ?PaymentRequest
    {
        if ($paymentRequestId) {
            $byId = PaymentRequest::query()->find($paymentRequestId);
            if ($byId !== null) {
                return $byId;
            }
        }

        return PaymentRequest::query()
            ->where('transaction_id', $reference)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAdditionalData(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function markPlacementAttempted(PaymentRequest $paymentRequest): void
    {
        if (! Schema::hasColumn('payment_requests', 'placement_status')) {
            return;
        }

        PaymentRequest::query()
            ->where('id', $paymentRequest->id)
            ->update([
                'placement_status' => PaymentRequest::PLACEMENT_PENDING,
                'placement_attempted_at' => now(),
                'placement_error' => null,
            ]);
    }

    private function markPlacementSuccess(PaymentRequest $paymentRequest, int $orderId): void
    {
        if (! Schema::hasColumn('payment_requests', 'placement_status')) {
            return;
        }

        PaymentRequest::query()
            ->where('id', $paymentRequest->id)
            ->update([
                'placement_status' => PaymentRequest::PLACEMENT_PLACED,
                'placed_order_id' => $orderId,
                'placement_error' => null,
                'placement_attempted_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function markPlacementFailed(PaymentRequest $paymentRequest, array $error, bool $terminal = false): void
    {
        if (! Schema::hasColumn('payment_requests', 'placement_status')) {
            return;
        }

        if ($terminal) {
            $error['terminal'] = true;
        }

        PaymentRequest::query()
            ->where('id', $paymentRequest->id)
            ->update([
                'placement_status' => $terminal
                    ? PaymentRequest::PLACEMENT_FAILED_TERMINAL
                    : PaymentRequest::PLACEMENT_FAILED,
                'placement_error' => json_encode($error),
                'placement_attempted_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>|null  $error
     * @return array{
     *     order_id: ?int,
     *     order_placed: bool,
     *     placement_status: string,
     *     placement_error: ?array<string, mixed>
     * }
     */
    private function buildResult(?int $orderId, bool $orderPlaced, string $placementStatus, ?array $error): array
    {
        return [
            'order_id' => $orderId,
            'order_placed' => $orderPlaced,
            'placement_status' => $placementStatus,
            'placement_error' => $error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlacementError(string $code, string $message, int $httpStatus, string $source): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'http_status' => $httpStatus,
            'source' => $source,
            'attempted_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlacementErrorFromResponse(JsonResponse $response): array
    {
        $data = $response->getData(true);
        $firstError = null;
        if (is_array($data) && isset($data['errors']) && is_array($data['errors'])) {
            $firstError = $data['errors'][0] ?? null;
        }

        $code = is_array($firstError) ? (string) ($firstError['code'] ?? 'placement_rejected') : 'placement_rejected';
        $message = is_array($firstError)
            ? (string) ($firstError['message'] ?? 'Order placement was rejected.')
            : 'Order placement was rejected.';

        return $this->buildPlacementError(
            code: $code,
            message: $message,
            httpStatus: $response->getStatusCode(),
            source: 'order_place'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchPlaceOrder(array $payload, ?int $authenticatedUserId, ?string $paymentRequestId = null): JsonResponse
    {
        $previousUser = auth('api')->user();

        try {
            if ($authenticatedUserId !== null && $authenticatedUserId > 0) {
                $user = User::query()->find($authenticatedUserId);
                if ($user !== null) {
                    auth('api')->setUser($user);
                }
            }

            $request = Request::create(
                '/api/v1/customer/order/place',
                'POST',
                $payload
            );
            $request->headers->set('Accept', 'application/json');
            $request->attributes->set('paystack_internal_dispatch', true);
            if (is_string($paymentRequestId) && $paymentRequestId !== '') {
                $request->headers->set('Idempotency-Key', 'paystack.pr.'.preg_replace('/[^A-Za-z0-9._~-]/', '', $paymentRequestId));
            }

            return app(OrderController::class)->placeOrder($request);
        } finally {
            if ($previousUser !== null) {
                auth('api')->setUser($previousUser);
            } else {
                auth('api')->forgetUser();
            }
        }
    }

    private function extractOrderIdFromResponse(JsonResponse $response): ?int
    {
        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->getData(true);
        if (! is_array($data)) {
            return null;
        }

        $orderId = $data['order_id'] ?? null;

        return is_numeric($orderId) ? (int) $orderId : null;
    }
}
