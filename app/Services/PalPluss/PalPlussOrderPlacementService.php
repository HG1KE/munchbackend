<?php

namespace App\Services\PalPluss;

use App\Http\Controllers\Api\V1\OrderController;
use App\Model\Order;
use App\Models\PaymentRequest;
use App\Support\OnlineCheckoutIdempotency;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Places a Munch order after a PalPluss payment is verified SUCCESS.
 * Mirrors PaystackOrderProtectionService placement mechanics with payment_method=palpluss.
 */
class PalPlussOrderPlacementService
{
    /**
     * @return array{
     *     order_id: ?int,
     *     order_placed: bool,
     *     placement_status: string,
     *     placement_error: ?array<string, mixed>
     * }
     */
    public function completeAfterVerifiedPayment(PaymentRequest $paymentRequest, string $transactionId): array
    {
        if ($paymentRequest->attribute !== 'order') {
            return $this->buildResult(null, false, PaymentRequest::PLACEMENT_PENDING, null);
        }

        $paymentRequestId = $paymentRequest->id;

        return DB::transaction(function () use ($paymentRequestId, $transactionId) {
            $paymentRequest = PaymentRequest::query()
                ->whereKey($paymentRequestId)
                ->lockForUpdate()
                ->first();

            if ($paymentRequest === null) {
                return $this->buildResult(null, false, PaymentRequest::PLACEMENT_PENDING, null);
            }

            $additional = $this->decodeAdditionalData($paymentRequest->additional_data);
            $draft = $this->resolvePlaceOrderDraft($paymentRequest) ?? [];
            $checkoutUuid = OnlineCheckoutIdempotency::normalize($additional['online_checkout_uuid'] ?? null)
                ?? OnlineCheckoutIdempotency::resolveFromArray($draft);

            $existingOrderId = $paymentRequest->placed_order_id
                ? (int) $paymentRequest->placed_order_id
                : $this->findExistingOrderId($transactionId, $checkoutUuid);

            if ($existingOrderId !== null) {
                $this->markPlacementSuccess($paymentRequest, $existingOrderId);

                return $this->buildResult($existingOrderId, true, PaymentRequest::PLACEMENT_PLACED, null);
            }

            if ((int) $paymentRequest->is_paid !== 1) {
                return $this->buildResult(
                    null,
                    false,
                    (string) ($paymentRequest->placement_status ?? PaymentRequest::PLACEMENT_PENDING),
                    null
                );
            }

            $draft = $draft !== [] ? $draft : ($this->resolvePlaceOrderDraft($paymentRequest) ?? []);
            if ($draft === []) {
                $error = $this->buildPlacementError(
                    'missing_place_order_draft',
                    'Checkout draft is missing on the payment session.',
                    422
                );
                $this->markPlacementFailed($paymentRequest, $error, terminal: true);
                Log::critical('palpluss.paid_without_order', [
                    'payment_id' => $paymentRequest->id,
                    'transaction_id' => $transactionId,
                    'stage' => 'missing_place_order_draft',
                ]);

                return $this->buildResult(null, false, PaymentRequest::PLACEMENT_FAILED_TERMINAL, $error);
            }

            $isGuest = (int) ($additional['checkout_is_guest'] ?? 0);
            $customerId = $additional['checkout_customer_id'] ?? $paymentRequest->payer_id;
            $guestId = $additional['checkout_guest_id'] ?? ($isGuest ? $customerId : null);

            $payload = $this->applyFrozenCouponSnapshot($draft, $additional);
            $payload = array_merge($payload, [
                'payment_method' => 'palpluss',
                'transaction_reference' => $transactionId,
            ]);
            if ($checkoutUuid !== null) {
                $payload['online_checkout_uuid'] = $checkoutUuid;
            }
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
                    Log::info('palpluss.order_placed', [
                        'payment_id' => $paymentRequest->id,
                        'transaction_id' => $transactionId,
                        'order_id' => $orderId,
                    ]);

                    return $this->buildResult($orderId, true, PaymentRequest::PLACEMENT_PLACED, null);
                }

                $error = $this->buildPlacementErrorFromResponse($response);
                $this->markPlacementFailed($paymentRequest, $error);
                Log::critical('palpluss.paid_without_order', [
                    'payment_id' => $paymentRequest->id,
                    'transaction_id' => $transactionId,
                    'stage' => 'order_place_rejected',
                    'placement_error' => $error,
                ]);

                return $this->buildResult(null, false, PaymentRequest::PLACEMENT_FAILED, $error);
            } catch (Throwable $exception) {
                $error = $this->buildPlacementError(
                    'placement_exception',
                    $exception->getMessage(),
                    500
                );
                $this->markPlacementFailed($paymentRequest, $error);
                Log::critical('palpluss.paid_without_order', [
                    'payment_id' => $paymentRequest->id,
                    'transaction_id' => $transactionId,
                    'stage' => 'order_place_exception',
                    'message' => $exception->getMessage(),
                ]);

                return $this->buildResult(null, false, PaymentRequest::PLACEMENT_FAILED, $error);
            }
        });
    }

    public function findExistingOrderId(string $transactionId, ?string $checkoutUuid = null): ?int
    {
        $reference = trim($transactionId);
        if ($reference !== '') {
            $order = Order::query()
                ->where('payment_method', 'palpluss')
                ->where('transaction_reference', $reference)
                ->orderByDesc('id')
                ->first();

            if ($order) {
                return (int) $order->id;
            }
        }

        $uuid = OnlineCheckoutIdempotency::normalize($checkoutUuid);
        if ($uuid !== null) {
            $byCheckout = OnlineCheckoutIdempotency::findOrder($uuid);
            if ($byCheckout) {
                return (int) $byCheckout->id;
            }
        }

        return null;
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

            $request = Request::create('/api/v1/customer/order/place', 'POST', $payload);
            $request->headers->set('Accept', 'application/json');
            $request->attributes->set('palpluss_internal_dispatch', true);
            if (is_string($paymentRequestId) && $paymentRequestId !== '') {
                $request->headers->set(
                    'Idempotency-Key',
                    'palpluss.pr.'.preg_replace('/[^A-Za-z0-9._~-]/', '', $paymentRequestId)
                );
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
        $legacy = $additional['place_order_draft'] ?? null;

        return is_array($legacy) && $legacy !== [] ? $legacy : null;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $additional
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

        PaymentRequest::query()->where('id', $paymentRequest->id)->update([
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

        PaymentRequest::query()->where('id', $paymentRequest->id)->update([
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

        PaymentRequest::query()->where('id', $paymentRequest->id)->update([
            'placement_status' => $terminal
                ? PaymentRequest::PLACEMENT_FAILED_TERMINAL
                : PaymentRequest::PLACEMENT_FAILED,
            'placement_error' => json_encode($error),
            'placement_attempted_at' => now(),
        ]);
    }

    /**
     * @return array{order_id: ?int, order_placed: bool, placement_status: string, placement_error: ?array<string, mixed>}
     */
    private function buildResult(?int $orderId, bool $orderPlaced, string $status, ?array $error): array
    {
        return [
            'order_id' => $orderId,
            'order_placed' => $orderPlaced,
            'placement_status' => $status,
            'placement_error' => $error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlacementError(string $code, string $message, int $httpStatus): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'http_status' => $httpStatus,
            'source' => 'palpluss',
            'attempted_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlacementErrorFromResponse(JsonResponse $response): array
    {
        $data = $response->getData(true);
        $first = null;
        if (is_array($data) && isset($data['errors'][0]) && is_array($data['errors'][0])) {
            $first = $data['errors'][0];
        }

        return $this->buildPlacementError(
            is_array($first) ? (string) ($first['code'] ?? 'placement_rejected') : 'placement_rejected',
            is_array($first) ? (string) ($first['message'] ?? 'Order placement was rejected.') : 'Order placement was rejected.',
            $response->getStatusCode()
        );
    }
}
