<?php

namespace App\Services\PalPluss;

use App\Exceptions\PalPlussException;
use App\Models\PalPlussPaymentAttempt;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PalPlussFulfillmentService
{
    public const SOURCE_WEBHOOK = 'webhook';

    public const SOURCE_BROWSER_VERIFY = 'browser_verify';

    public const SOURCE_RECONCILIATION = 'reconciliation';

    public function __construct(
        private readonly PalPlussTransactionVerifier $verifier,
        private readonly PalPlussOrderPlacementService $placement,
    ) {
    }

    /**
     * @return array{
     *     outcome: string,
     *     status: string,
     *     transaction_id: string,
     *     payment_request: ?PaymentRequest,
     *     order_id: ?int,
     *     order_placed: bool,
     *     placement_status: ?string,
     *     message: ?string
     * }
     */
    public function fulfill(string $transactionId, string $source, ?string $paymentRequestId = null): array
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            return $this->result('failed', 'failed', '', null, null, false, null, 'Transaction ID required.');
        }

        try {
            return DB::transaction(function () use ($transactionId, $source, $paymentRequestId) {
                $attempt = PalPlussPaymentAttempt::query()
                    ->where('transaction_id', $transactionId)
                    ->lockForUpdate()
                    ->first();

                if ($attempt === null && $paymentRequestId) {
                    $attempt = PalPlussPaymentAttempt::query()
                        ->where('payment_request_id', $paymentRequestId)
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->first();
                }

                if ($attempt === null) {
                    Log::warning('palpluss.fulfillment_unknown_transaction', [
                        'transaction_id' => $transactionId,
                        'source' => $source,
                    ]);

                    return $this->result(
                        'failed',
                        'failed',
                        $transactionId,
                        null,
                        null,
                        false,
                        null,
                        'Unknown PalPluss transaction.'
                    );
                }

                $paymentRequest = PaymentRequest::query()
                    ->where('id', $attempt->payment_request_id)
                    ->lockForUpdate()
                    ->first();

                if ($paymentRequest === null) {
                    return $this->result(
                        'failed',
                        'failed',
                        $transactionId,
                        null,
                        null,
                        false,
                        null,
                        'Payment session not found.'
                    );
                }

                $existingOrderId = $this->placement->findExistingOrderId(
                    (string) $attempt->transaction_id,
                    null
                );
                if ($existingOrderId !== null || $paymentRequest->hasPlacedOrder()) {
                    $orderId = $existingOrderId ?? (int) $paymentRequest->placed_order_id;
                    $attempt->placed_order_id = $orderId;
                    $attempt->fulfilled_at = $attempt->fulfilled_at ?? now();
                    $attempt->status = PalPlussPaymentAttempt::STATUS_SUCCESS;
                    $attempt->save();

                    return $this->result(
                        'already_placed',
                        'success',
                        (string) $attempt->transaction_id,
                        $paymentRequest->fresh(),
                        $orderId,
                        true,
                        PaymentRequest::PLACEMENT_PLACED,
                        null
                    );
                }

                // Always re-verify with PalPluss — webhooks have no documented signature.
                $verified = $this->verifier->fetch((string) $attempt->transaction_id);
                $mapped = $this->verifier->mapApiStatusToAttemptStatus($verified['status']);
                $attempt->status = $mapped;
                $attempt->mpesa_receipt = $verified['mpesa_receipt'] ?? $attempt->mpesa_receipt;
                $attempt->result_code = $verified['result_code'] ?? $attempt->result_code;
                $attempt->result_desc = $verified['result_desc'] ?? $attempt->result_desc;
                $attempt->provider_request_id = $verified['provider_request_id'] ?? $attempt->provider_request_id;
                $attempt->provider_checkout_id = $verified['provider_checkout_id'] ?? $attempt->provider_checkout_id;

                if ($mapped !== PalPlussPaymentAttempt::STATUS_SUCCESS) {
                    if ($attempt->isTerminalFailure()) {
                        $attempt->terminal_at = $attempt->terminal_at ?? now();
                        if ($paymentRequest->placement_status === PaymentRequest::PLACEMENT_PENDING) {
                            $paymentRequest->placement_status = PaymentRequest::PLACEMENT_GATEWAY_NOT_PAID;
                            $paymentRequest->save();
                        }
                    }
                    $attempt->save();

                    return $this->result(
                        'not_paid',
                        strtolower($verified['status']),
                        (string) $attempt->transaction_id,
                        $paymentRequest->fresh(),
                        null,
                        false,
                        $paymentRequest->placement_status,
                        'Payment not successful yet.'
                    );
                }

                $this->verifier->assertSuccessfulForPayment($paymentRequest, $attempt, $verified);

                $attempt->status = PalPlussPaymentAttempt::STATUS_SUCCESS;
                $attempt->terminal_at = $attempt->terminal_at ?? now();
                $attempt->save();

                PaymentRequest::query()->where('id', $paymentRequest->id)->update([
                    'is_paid' => 1,
                    'payment_method' => 'palpluss',
                    'transaction_id' => $attempt->transaction_id,
                ]);

                $paymentRequest = $paymentRequest->fresh() ?? $paymentRequest;

                if ($paymentRequest->attribute !== 'order') {
                    PaymentRequest::query()->where('id', $paymentRequest->id)->update([
                        'placement_status' => PaymentRequest::PLACEMENT_RECONCILED,
                    ]);

                    return $this->result(
                        'verified_non_order',
                        'success',
                        (string) $attempt->transaction_id,
                        $paymentRequest->fresh(),
                        null,
                        false,
                        PaymentRequest::PLACEMENT_RECONCILED,
                        null
                    );
                }

                $placement = $this->placement->completeAfterVerifiedPayment(
                    $paymentRequest->fresh() ?? $paymentRequest,
                    (string) $attempt->transaction_id
                );

                if (($placement['order_id'] ?? null) !== null) {
                    $attempt->placed_order_id = (int) $placement['order_id'];
                    $attempt->fulfilled_at = now();
                    $attempt->save();
                }

                Log::info('palpluss.fulfillment_complete', [
                    'transaction_id' => $attempt->transaction_id,
                    'source' => $source,
                    'outcome' => ($placement['order_placed'] ?? false) ? 'order_placed' : 'verified_not_placed',
                    'order_id' => $placement['order_id'] ?? null,
                ]);

                return $this->result(
                    ($placement['order_placed'] ?? false) ? 'order_placed' : 'verified_not_placed',
                    'success',
                    (string) $attempt->transaction_id,
                    $paymentRequest->fresh(),
                    $placement['order_id'] ?? null,
                    (bool) ($placement['order_placed'] ?? false),
                    $placement['placement_status'] ?? null,
                    null
                );
            });
        } catch (PalPlussException $e) {
            Log::warning('palpluss.fulfillment_rejected', [
                'transaction_id' => $transactionId,
                'source' => $source,
                'error_code' => $e->errorCode,
                'message' => $e->getMessage(),
            ]);

            return $this->result(
                'failed',
                'failed',
                $transactionId,
                null,
                null,
                false,
                null,
                $e->getMessage()
            );
        } catch (Throwable $e) {
            Log::error('palpluss.fulfillment_exception', [
                'transaction_id' => $transactionId,
                'source' => $source,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array{
     *     outcome: string,
     *     status: string,
     *     transaction_id: string,
     *     payment_request: ?PaymentRequest,
     *     order_id: ?int,
     *     order_placed: bool,
     *     placement_status: ?string,
     *     message: ?string
     * }
     */
    private function result(
        string $outcome,
        string $status,
        string $transactionId,
        ?PaymentRequest $paymentRequest,
        ?int $orderId,
        bool $orderPlaced,
        ?string $placementStatus,
        ?string $message,
    ): array {
        return [
            'outcome' => $outcome,
            'status' => $status,
            'transaction_id' => $transactionId,
            'payment_request' => $paymentRequest,
            'order_id' => $orderId,
            'order_placed' => $orderPlaced,
            'placement_status' => $placementStatus,
            'message' => $message,
        ];
    }
}
