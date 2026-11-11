<?php

namespace App\Services\PalPluss;

use App\Exceptions\PalPlussException;
use App\Models\PalPlussPaymentAttempt;
use App\Models\PalPlussUnmatchedTransaction;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PalPlussFulfillmentService
{
    public const SOURCE_WEBHOOK = 'webhook';

    public const SOURCE_BROWSER_VERIFY = 'browser_verify';

    public const SOURCE_RECONCILIATION = 'reconciliation';

    public function __construct(
        private readonly PalPlussTransactionVerifier $verifier,
        private readonly PalPlussOrderPlacementService $placement,
        private readonly PalPlussPhoneNormalizer $phoneNormalizer,
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
            // Provider HTTP must stay outside the DB transaction so a slow PalPluss
            // round-trip cannot hold row locks (and so orphan matching can use the body).
            $prefetched = null;
            $attemptId = $this->findAttemptId($transactionId, $paymentRequestId);

            if ($attemptId === null) {
                $prefetched = $this->verifier->fetch($transactionId);
                $attemptId = $this->findOrphanAttemptId($prefetched);
            }

            if ($attemptId === null) {
                $this->recordUnmatchedSuccess($transactionId, $prefetched, $source);

                Log::warning('palpluss.fulfillment_unknown_transaction', [
                    'transaction_id' => $transactionId,
                    'source' => $source,
                    'external_reference' => $prefetched['external_reference'] ?? null,
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

            return DB::transaction(function () use ($transactionId, $source, $paymentRequestId, $attemptId, $prefetched) {
                $attempt = PalPlussPaymentAttempt::query()
                    ->whereKey($attemptId)
                    ->lockForUpdate()
                    ->first();

                if ($attempt === null) {
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

                $this->bindTransactionId($attempt, $transactionId);

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
                    $this->markUnmatchedResolved($transactionId, (int) $attempt->id);

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
                $verified = $prefetched ?? $this->verifier->fetch((string) $attempt->transaction_id);
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
                $this->markUnmatchedResolved($transactionId, (int) $attempt->id);

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
                    'payment_request_id' => $paymentRequestId,
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

    private function findAttemptId(string $transactionId, ?string $paymentRequestId): ?int
    {
        $byTxn = PalPlussPaymentAttempt::query()
            ->where('transaction_id', $transactionId)
            ->value('id');
        if ($byTxn !== null) {
            return (int) $byTxn;
        }

        if (! is_string($paymentRequestId) || $paymentRequestId === '') {
            return null;
        }

        $byPr = PalPlussPaymentAttempt::query()
            ->where('payment_request_id', $paymentRequestId)
            ->where(function ($q) use ($transactionId) {
                $q->whereNull('transaction_id')
                    ->orWhere('transaction_id', '')
                    ->orWhere('transaction_id', $transactionId);
            })
            ->whereIn('status', [
                PalPlussPaymentAttempt::STATUS_INITIATED,
                PalPlussPaymentAttempt::STATUS_PENDING,
                PalPlussPaymentAttempt::STATUS_PROCESSING,
                PalPlussPaymentAttempt::STATUS_SUCCESS,
            ])
            ->orderByDesc('id')
            ->value('id');

        return $byPr !== null ? (int) $byPr : null;
    }

    /**
     * Match SUCCESS orphans whose attempt never stored transaction_id
     * (e.g. php-fpm recycled mid-STK after PalPluss accepted the push).
     *
     * @param  array<string, mixed>  $verified
     */
    private function findOrphanAttemptId(array $verified): ?int
    {
        $lookbackHours = max(1, (int) config('palpluss.reconcile_lookback_hours', 48));
        $windowStart = now()->subHours($lookbackHours);
        $tolerance = (float) config('palpluss.amount_tolerance_major', 1.0);
        $amount = isset($verified['amount']) ? (float) $verified['amount'] : null;

        $base = PalPlussPaymentAttempt::query()
            ->where(function ($q) {
                $q->whereNull('transaction_id')->orWhere('transaction_id', '');
            })
            ->whereIn('status', [
                PalPlussPaymentAttempt::STATUS_INITIATED,
                PalPlussPaymentAttempt::STATUS_PENDING,
                PalPlussPaymentAttempt::STATUS_PROCESSING,
            ])
            ->where('created_at', '>=', $windowStart);

        $external = trim((string) ($verified['external_reference'] ?? ''));
        if ($external !== '') {
            $byRef = (clone $base)
                ->where('account_reference', $external)
                ->orderBy('id')
                ->get();

            $matched = $this->pickUniqueAmountMatch($byRef, $amount, $tolerance);
            if ($matched !== null) {
                Log::info('palpluss.orphan_matched_by_external_reference', [
                    'transaction_id' => $verified['transaction_id'] ?? null,
                    'attempt_id' => $matched,
                    'external_reference' => $external,
                ]);

                return $matched;
            }
        }

        $rawPhone = isset($verified['phone_number']) ? (string) $verified['phone_number'] : '';
        $phone = $this->phoneNormalizer->normalize($rawPhone) ?? ($rawPhone !== '' ? $rawPhone : null);
        if ($phone === null || $amount === null) {
            return null;
        }

        $phoneWindowMinutes = max(5, (int) config('palpluss.orphan_phone_match_minutes', 45));
        $byPhone = PalPlussPaymentAttempt::query()
            ->where(function ($q) {
                $q->whereNull('transaction_id')->orWhere('transaction_id', '');
            })
            ->whereIn('status', [
                PalPlussPaymentAttempt::STATUS_INITIATED,
                PalPlussPaymentAttempt::STATUS_PENDING,
                PalPlussPaymentAttempt::STATUS_PROCESSING,
            ])
            ->where('phone', $phone)
            ->where('created_at', '>=', now()->subMinutes($phoneWindowMinutes))
            ->orderBy('id')
            ->get();

        $matched = $this->pickUniqueAmountMatch($byPhone, $amount, $tolerance);
        if ($matched !== null) {
            Log::info('palpluss.orphan_matched_by_phone_amount', [
                'transaction_id' => $verified['transaction_id'] ?? null,
                'attempt_id' => $matched,
                'amount' => $amount,
            ]);
        }

        return $matched;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PalPlussPaymentAttempt>  $candidates
     */
    private function pickUniqueAmountMatch($candidates, ?float $amount, float $tolerance): ?int
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        if ($amount === null) {
            return $candidates->count() === 1 ? (int) $candidates->first()->id : null;
        }

        $amountMatches = $candidates->filter(
            fn (PalPlussPaymentAttempt $a) => abs((float) $a->amount - $amount) <= $tolerance
        );

        return $amountMatches->count() === 1 ? (int) $amountMatches->first()->id : null;
    }

    private function bindTransactionId(PalPlussPaymentAttempt $attempt, string $transactionId): void
    {
        $current = trim((string) ($attempt->transaction_id ?? ''));
        if ($current === $transactionId) {
            return;
        }

        if ($current !== '' && $current !== $transactionId) {
            throw new PalPlussException(
                'Attempt already bound to a different PalPluss transaction.',
                'TX_MISMATCH',
                422
            );
        }

        $conflict = PalPlussPaymentAttempt::query()
            ->where('transaction_id', $transactionId)
            ->where('id', '!=', $attempt->id)
            ->exists();
        if ($conflict) {
            throw new PalPlussException(
                'PalPluss transaction already bound to another attempt.',
                'TX_ALREADY_BOUND',
                409
            );
        }

        $attempt->transaction_id = $transactionId;
        $attempt->stk_initiated_at = $attempt->stk_initiated_at ?? now();
        if ($attempt->status === PalPlussPaymentAttempt::STATUS_INITIATED) {
            $attempt->status = PalPlussPaymentAttempt::STATUS_PENDING;
        }
        $attempt->save();

        PaymentRequest::query()
            ->where('id', $attempt->payment_request_id)
            ->where(function ($q) use ($transactionId) {
                $q->whereNull('transaction_id')
                    ->orWhere('transaction_id', '')
                    ->orWhere('transaction_id', $transactionId);
            })
            ->update([
                'payment_method' => 'palpluss',
                'transaction_id' => $transactionId,
            ]);

        Log::info('palpluss.transaction_id_bound', [
            'attempt_id' => $attempt->id,
            'transaction_id' => $transactionId,
            'payment_request_id' => $attempt->payment_request_id,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $verified
     */
    private function recordUnmatchedSuccess(string $transactionId, ?array $verified, string $source): void
    {
        if (! Schema::hasTable('palpluss_unmatched_transactions')) {
            return;
        }

        $status = strtoupper((string) ($verified['status'] ?? ''));
        if ($verified === null || $status !== 'SUCCESS') {
            return;
        }

        PalPlussUnmatchedTransaction::query()->updateOrCreate(
            ['transaction_id' => $transactionId],
            [
                'external_reference' => $verified['external_reference'] ?? null,
                'phone' => $verified['phone_number'] ?? null,
                'amount' => $verified['amount'] ?? null,
                'currency' => $verified['currency'] ?? null,
                'status' => $status,
                'mpesa_receipt' => $verified['mpesa_receipt'] ?? null,
                'payload' => [
                    'source' => $source,
                    'verified' => [
                        'transaction_id' => $verified['transaction_id'] ?? $transactionId,
                        'status' => $verified['status'] ?? null,
                        'amount' => $verified['amount'] ?? null,
                        'currency' => $verified['currency'] ?? null,
                        'external_reference' => $verified['external_reference'] ?? null,
                        'phone_number' => $verified['phone_number'] ?? null,
                        'mpesa_receipt' => $verified['mpesa_receipt'] ?? null,
                    ],
                ],
                'resolved_at' => null,
                'resolved_attempt_id' => null,
            ]
        );

        Log::warning('palpluss.unmatched_success_recorded', [
            'transaction_id' => $transactionId,
            'external_reference' => $verified['external_reference'] ?? null,
            'source' => $source,
        ]);
    }

    private function markUnmatchedResolved(string $transactionId, int $attemptId): void
    {
        if (! Schema::hasTable('palpluss_unmatched_transactions')) {
            return;
        }

        PalPlussUnmatchedTransaction::query()
            ->where('transaction_id', $transactionId)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'resolved_attempt_id' => $attemptId,
            ]);
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
