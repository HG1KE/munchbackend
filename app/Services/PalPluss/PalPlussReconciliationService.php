<?php

namespace App\Services\PalPluss;

use App\Models\PalPlussPaymentAttempt;
use App\Models\PalPlussUnmatchedTransaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PalPlussReconciliationService
{
    public function __construct(
        private readonly PalPlussFulfillmentService $fulfillment,
    ) {
    }

    /**
     * @return array{
     *     scanned: int,
     *     unmatched_scanned: int,
     *     fulfilled: int,
     *     already_placed: int,
     *     not_paid: int,
     *     failed: int
     * }
     */
    public function reconcile(?string $transactionId = null): array
    {
        $stats = [
            'scanned' => 0,
            'unmatched_scanned' => 0,
            'fulfilled' => 0,
            'already_placed' => 0,
            'not_paid' => 0,
            'failed' => 0,
        ];

        if (is_string($transactionId) && trim($transactionId) !== '') {
            $this->applyOutcome(
                $this->fulfillment->fulfill(
                    trim($transactionId),
                    PalPlussFulfillmentService::SOURCE_RECONCILIATION
                ),
                $stats
            );
            $stats['scanned'] = 1;
            Log::info('palpluss.reconcile_complete', $stats);

            return $stats;
        }

        $this->reconcileUnmatchedSuccesses($stats);
        $this->reconcileKnownAttempts($stats);

        Log::info('palpluss.reconcile_complete', $stats);

        return $stats;
    }

    /**
     * @param  array{scanned: int, unmatched_scanned: int, fulfilled: int, already_placed: int, not_paid: int, failed: int}  $stats
     */
    private function reconcileUnmatchedSuccesses(array &$stats): void
    {
        if (! Schema::hasTable('palpluss_unmatched_transactions')) {
            return;
        }

        $lookbackHours = max(1, (int) config('palpluss.reconcile_lookback_hours', 48));
        $limit = max(1, (int) config('palpluss.reconcile_limit', 50));

        $orphans = PalPlussUnmatchedTransaction::query()
            ->whereNull('resolved_at')
            ->where('created_at', '>=', now()->subHours($lookbackHours))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $stats['unmatched_scanned'] = $orphans->count();

        foreach ($orphans as $orphan) {
            $this->applyOutcome(
                $this->fulfillment->fulfill(
                    (string) $orphan->transaction_id,
                    PalPlussFulfillmentService::SOURCE_RECONCILIATION
                ),
                $stats
            );
        }
    }

    /**
     * @param  array{scanned: int, unmatched_scanned: int, fulfilled: int, already_placed: int, not_paid: int, failed: int}  $stats
     */
    private function reconcileKnownAttempts(array &$stats): void
    {
        $lookbackHours = max(1, (int) config('palpluss.reconcile_lookback_hours', 48));
        $limit = max(1, (int) config('palpluss.reconcile_limit', 50));

        $attempts = PalPlussPaymentAttempt::query()
            ->whereNotNull('transaction_id')
            ->where('transaction_id', '!=', '')
            ->where(function ($q) {
                $q->whereNull('placed_order_id')
                    ->orWhere('placed_order_id', 0);
            })
            ->where('created_at', '>=', now()->subHours($lookbackHours))
            ->whereIn('status', [
                PalPlussPaymentAttempt::STATUS_PENDING,
                PalPlussPaymentAttempt::STATUS_PROCESSING,
                PalPlussPaymentAttempt::STATUS_SUCCESS,
                PalPlussPaymentAttempt::STATUS_INITIATED,
            ])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $stats['scanned'] = $attempts->count();

        foreach ($attempts as $attempt) {
            $this->applyOutcome(
                $this->fulfillment->fulfill(
                    (string) $attempt->transaction_id,
                    PalPlussFulfillmentService::SOURCE_RECONCILIATION,
                    (string) $attempt->payment_request_id
                ),
                $stats
            );
        }
    }

    /**
     * @param  array{outcome: string, status: string, transaction_id: string, payment_request: mixed, order_id: ?int, order_placed: bool, placement_status: ?string, message: ?string}  $result
     * @param  array{scanned: int, unmatched_scanned: int, fulfilled: int, already_placed: int, not_paid: int, failed: int}  $stats
     */
    private function applyOutcome(array $result, array &$stats): void
    {
        $outcome = (string) ($result['outcome'] ?? 'failed');
        match ($outcome) {
            'order_placed' => $stats['fulfilled']++,
            'already_placed', 'verified_non_order' => $stats['already_placed']++,
            'not_paid' => $stats['not_paid']++,
            default => $stats['failed']++,
        };
    }
}
