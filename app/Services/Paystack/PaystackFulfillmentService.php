<?php

namespace App\Services\Paystack;

use App\Exceptions\PaystackException;
use App\Models\PaymentRequest;
use App\Services\PaystackService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Idempotent post-payment fulfillment for Paystack order checkout.
 *
 * Shared by browser verify, webhooks, and scheduled reconciliation.
 */
class PaystackFulfillmentService
{
    public function __construct(
        private readonly PaystackOrderProtectionService $protection,
        private readonly PaystackService $paystack,
    ) {
    }

    /**
     * Fulfill a successful Paystack charge: verify (when needed), mark paid, place order.
     *
     * @return array{
     *     outcome: string,
     *     status: string,
     *     reference: string,
     *     payment_request: ?PaymentRequest,
     *     payment_details: ?array<string, mixed>,
     *     order_id: ?int,
     *     order_placed: bool,
     *     placement_status: ?string,
     *     placement_error: ?array<string, mixed>,
     *     message: ?string
     * }
     */
    public function fulfillPaidCharge(
        string $reference,
        string $source,
        ?string $paymentRequestId = null,
    ): array {
        $reference = trim($reference);
        $baseContext = [
            'reference' => $reference,
            'source' => $source,
            'payment_request_id' => $paymentRequestId,
        ];

        if ($reference === '') {
            return $this->buildResult(
                outcome: 'failed',
                status: 'failed',
                reference: $reference,
                paymentRequest: null,
                paymentDetails: null,
                placement: null,
                message: 'Paystack reference is required.',
            );
        }

        if (! $this->paystack->isConfigured()) {
            return $this->buildResult(
                outcome: 'failed',
                status: 'failed',
                reference: $reference,
                paymentRequest: null,
                paymentDetails: null,
                placement: null,
                message: 'Paystack is not configured.',
            );
        }

        try {
            return DB::transaction(function () use ($reference, $source, $paymentRequestId, $baseContext) {
                $paymentRequest = $this->resolvePaymentRequestByIdOrReference($paymentRequestId, $reference);
                if ($paymentRequest !== null) {
                    $paymentRequest = PaymentRequest::query()
                        ->where('id', $paymentRequest->id)
                        ->lockForUpdate()
                        ->first() ?? $paymentRequest;
                }

                $existingOrderId = $this->resolveExistingOrderId($paymentRequest, $reference);
                if ($existingOrderId !== null) {
                    if ($paymentRequest !== null) {
                        $this->protection->completeAfterVerify($paymentRequest->fresh() ?? $paymentRequest);
                    }

                    Log::info('paystack.fulfillment_already_placed', array_merge($baseContext, [
                        'payment_request_id' => $paymentRequest?->id,
                        'order_id' => $existingOrderId,
                    ]));

                    return $this->buildResult(
                        outcome: 'already_placed',
                        status: 'success',
                        reference: $reference,
                        paymentRequest: $paymentRequest?->fresh(),
                        paymentDetails: null,
                        placement: [
                            'order_id' => $existingOrderId,
                            'order_placed' => true,
                            'placement_status' => PaymentRequest::PLACEMENT_PLACED,
                            'placement_error' => null,
                        ],
                    );
                }

                $paymentDetails = null;
                $wasAlreadyVerified = $paymentRequest !== null && (int) $paymentRequest->is_paid === 1;

                if ($wasAlreadyVerified) {
                    Log::info('paystack.fulfillment_already_verified', array_merge($baseContext, [
                        'payment_request_id' => $paymentRequest->id,
                    ]));
                } else {
                    Log::info('paystack.fulfillment_verify', $baseContext);
                    $paymentDetails = $this->paystack->verifyTransaction($reference);

                    if (! $this->gatewayTransactionSuccessful($paymentDetails)) {
                        $gatewayStatus = (string) ($paymentDetails['data']['status'] ?? 'unknown');

                        return $this->buildResult(
                            outcome: 'not_paid',
                            status: 'failed',
                            reference: $reference,
                            paymentRequest: $paymentRequest,
                            paymentDetails: $paymentDetails,
                            placement: null,
                            message: 'Paystack payment is not successful (status: ' . $gatewayStatus . ').',
                        );
                    }

                    $paymentRequest = $this->resolvePaymentRequestAfterVerify(
                        $paymentRequest,
                        $reference,
                        $paymentDetails,
                        $paymentRequestId
                    );

                    if ($paymentRequest !== null) {
                        $this->protection->assertPaystackAmountMatches($paymentRequest, $paymentDetails);
                        $this->markPaymentRequestPaid($paymentRequest, $reference, $paymentDetails);
                    } else {
                        Log::info('paystack.fulfillment_no_payment_request', [
                            'reference' => $reference,
                            'source' => $source,
                        ]);
                    }
                }

                if ($paymentRequest === null || $paymentRequest->attribute !== 'order') {
                    $nonOrder = $paymentRequest !== null
                        ? $this->fulfillNonOrderPurpose($paymentRequest, $reference)
                        : ['fulfilled' => false, 'placement_status' => null, 'placement_error' => null];

                    return $this->buildResult(
                        outcome: 'verified_non_order',
                        status: 'success',
                        reference: $reference,
                        paymentRequest: $paymentRequest?->fresh(),
                        paymentDetails: $paymentDetails,
                        placement: [
                            'order_id' => null,
                            'order_placed' => false,
                            'placement_status' => $nonOrder['placement_status'],
                            'placement_error' => $nonOrder['placement_error'],
                        ],
                    );
                }

                $paymentRequest = $paymentRequest->fresh() ?? $paymentRequest;

                Log::info('paystack.fulfillment_order_placement_started', array_merge($baseContext, [
                    'payment_request_id' => $paymentRequest->id,
                ]));

                $placement = $this->protection->completeAfterVerify($paymentRequest, $paymentDetails);

                if ($placement['order_placed'] && $placement['order_id'] !== null) {
                    Log::info('paystack.fulfillment_order_placement_succeeded', array_merge($baseContext, [
                        'payment_request_id' => $paymentRequest->id,
                        'order_id' => $placement['order_id'],
                    ]));
                }

                return $this->buildResult(
                    outcome: $placement['order_placed'] ? 'order_placed' : 'verified_not_placed',
                    status: 'success',
                    reference: $reference,
                    paymentRequest: $paymentRequest->fresh(),
                    paymentDetails: $paymentDetails,
                    placement: $placement,
                );
            });
        } catch (PaystackException $exception) {
            Log::warning('paystack.fulfillment_failed', array_merge($baseContext, [
                'message' => $exception->getMessage(),
            ]));

            $paymentRequest = $this->resolvePaymentRequestByIdOrReference($paymentRequestId, $reference);
            $outcome = ($paymentRequest === null || (int) $paymentRequest->is_paid === 0)
                ? 'verify_failed'
                : 'failed';

            return $this->buildResult(
                outcome: $outcome,
                status: 'failed',
                reference: $reference,
                paymentRequest: $paymentRequest,
                paymentDetails: null,
                placement: null,
                message: $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            Log::error('paystack.fulfillment_exception', array_merge($baseContext, [
                'message' => $exception->getMessage(),
            ]));

            return $this->buildResult(
                outcome: 'failed',
                status: 'failed',
                reference: $reference,
                paymentRequest: null,
                paymentDetails: null,
                placement: null,
                message: $exception->getMessage(),
            );
        }
    }

    private function resolvePaymentRequestByIdOrReference(?string $paymentRequestId, string $reference): ?PaymentRequest
    {
        if ($paymentRequestId !== null && $paymentRequestId !== '') {
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
     * @param  array<string, mixed>  $paymentDetails
     */
    private function resolvePaymentRequestAfterVerify(
        ?PaymentRequest $paymentRequest,
        string $reference,
        array $paymentDetails,
        ?string $paymentRequestId,
    ): ?PaymentRequest {
        if ($paymentRequest !== null) {
            return $paymentRequest;
        }

        $metadata = $this->normalizeMetadata($paymentDetails['data']['metadata'] ?? []);
        $resolvedId = $metadata['payment_request_id'] ?? $paymentRequestId;

        if (is_string($resolvedId) && $resolvedId !== '') {
            $byId = PaymentRequest::query()->find($resolvedId);
            if ($byId !== null) {
                return $byId;
            }
        }

        $attributeId = $metadata['attribute_id'] ?? null;
        if ($attributeId !== null && $attributeId !== '') {
            return PaymentRequest::query()
                ->where('attribute_id', (string) $attributeId)
                ->where('payment_method', 'paystack')
                ->orderByDesc('created_at')
                ->first();
        }

        return PaymentRequest::query()
            ->where('transaction_id', $reference)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $paymentDetails
     */
    private function markPaymentRequestPaid(
        PaymentRequest $paymentRequest,
        string $reference,
        array $paymentDetails,
    ): void {
        $metadata = $this->normalizeMetadata($paymentDetails['data']['metadata'] ?? []);
        $attributeId = $metadata['attribute_id'] ?? $paymentRequest->attribute_id;

        PaymentRequest::query()
            ->where('id', $paymentRequest->id)
            ->update([
                'payment_method' => 'paystack',
                'is_paid' => 1,
                'transaction_id' => $reference,
                'attribute_id' => $attributeId ?? $paymentRequest->attribute_id,
            ]);
    }

    /**
     * @return array{fulfilled: bool, placement_status: ?string, placement_error: ?array<string, mixed>}
     */
    private function fulfillNonOrderPurpose(PaymentRequest $paymentRequest, string $reference): array
    {
        $session = PaymentRequest::query()->find($paymentRequest->id) ?? $paymentRequest;

        if ((int) $session->is_paid !== 1) {
            return [
                'fulfilled' => false,
                'placement_status' => (string) ($session->placement_status ?? PaymentRequest::PLACEMENT_PENDING),
                'placement_error' => null,
            ];
        }

        if (in_array(
            (string) ($session->placement_status ?? ''),
            PaymentRequest::excludedRecoveryPlacementStatuses(),
            true,
        )) {
            return [
                'fulfilled' => true,
                'placement_status' => (string) $session->placement_status,
                'placement_error' => null,
            ];
        }

        $hook = trim((string) ($session->success_hook ?? ''));

        if ($hook === '' || ! function_exists($hook)) {
            $error = [
                'code' => 'missing_success_hook',
                'message' => 'Paid non-order Paystack session has no callable success hook.',
                'source' => 'paystack_non_order_fulfillment',
            ];
            $this->recordPaidWithoutFulfillment($session, $reference, $error);

            PaymentRequest::query()
                ->where('id', $session->id)
                ->update(['placement_status' => PaymentRequest::PLACEMENT_PENDING]);

            return ['fulfilled' => false, 'placement_status' => PaymentRequest::PLACEMENT_PENDING, 'placement_error' => $error];
        }

        try {
            call_user_func($hook, $session);

            PaymentRequest::query()
                ->where('id', $session->id)
                ->update([
                    'placement_status' => PaymentRequest::PLACEMENT_RECONCILED,
                    'placement_attempted_at' => now(),
                    'placement_error' => null,
                ]);

            return ['fulfilled' => true, 'placement_status' => PaymentRequest::PLACEMENT_RECONCILED, 'placement_error' => null];
        } catch (Throwable $exception) {
            $error = [
                'code' => 'non_order_fulfillment_exception',
                'message' => $exception->getMessage(),
                'source' => 'paystack_non_order_fulfillment',
            ];
            $this->recordPaidWithoutFulfillment($session, $reference, $error);

            PaymentRequest::query()
                ->where('id', $session->id)
                ->update(['placement_status' => PaymentRequest::PLACEMENT_PENDING]);

            return ['fulfilled' => false, 'placement_status' => PaymentRequest::PLACEMENT_PENDING, 'placement_error' => $error];
        }
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function recordPaidWithoutFulfillment(PaymentRequest $paymentRequest, string $reference, array $error): void
    {
        Log::critical('paystack.paid_without_fulfillment', [
            'payment_id' => $paymentRequest->id,
            'attribute' => $paymentRequest->attribute,
            'success_hook' => $paymentRequest->success_hook,
            'reference' => $reference,
            'error' => $error,
        ]);
    }

    private function resolveExistingOrderId(?PaymentRequest $paymentRequest, string $reference): ?int
    {
        if ($paymentRequest !== null) {
            if ($paymentRequest->placed_order_id) {
                return (int) $paymentRequest->placed_order_id;
            }

            $sessionOrderId = $this->protection->placedOrderIdFromSession($paymentRequest);
            if ($sessionOrderId !== null) {
                return $sessionOrderId;
            }
        }

        return $this->protection->findExistingOrderId($reference);
    }

    /**
     * @param  array<string, mixed>|null  $placement
     * @return array{
     *     outcome: string,
     *     status: string,
     *     reference: string,
     *     payment_request: ?PaymentRequest,
     *     payment_details: ?array<string, mixed>,
     *     order_id: ?int,
     *     order_placed: bool,
     *     placement_status: ?string,
     *     placement_error: ?array<string, mixed>,
     *     message: ?string
     * }
     */
    private function buildResult(
        string $outcome,
        string $status,
        string $reference,
        ?PaymentRequest $paymentRequest,
        ?array $paymentDetails,
        ?array $placement,
        ?string $message = null,
    ): array {
        return [
            'outcome' => $outcome,
            'status' => $status,
            'reference' => $reference,
            'payment_request' => $paymentRequest,
            'payment_details' => $paymentDetails,
            'order_id' => $placement['order_id'] ?? null,
            'order_placed' => (bool) ($placement['order_placed'] ?? false),
            'placement_status' => $placement['placement_status'] ?? null,
            'placement_error' => $placement['placement_error'] ?? null,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $paymentDetails
     */
    private function gatewayTransactionSuccessful(array $paymentDetails): bool
    {
        return ($paymentDetails['status'] ?? false) === true
            && ($paymentDetails['data']['status'] ?? '') === 'success';
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMetadata(mixed $metadata): array
    {
        if (is_object($metadata)) {
            $metadata = (array) $metadata;
        }

        return is_array($metadata) ? $metadata : [];
    }
}
