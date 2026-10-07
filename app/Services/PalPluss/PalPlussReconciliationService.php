<?php

namespace App\Services\PalPluss;

use App\Models\PalPlussPaymentAttempt;
use Illuminate\Support\Facades\Log;

class PalPlussReconciliationService
{
    public function __construct(
        private readonly PalPlussFulfillmentService $fulfillment,
    ) {
    }

    /**
     * @return array{scanned: int, fulfilled: int, already_placed: int, not_paid: int, failed: int}
     */
    public function reconcile(): array
    {
        $lookbackHours = max(1, (int) config('palpluss.reconcile_lookback_hours', 48));
        $limit = max(1, (int) config('palpluss.reconcile_limit', 50));

        $attempts = PalPlussPaymentAttempt::query()
            ->whereNotNull('transaction_id')
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

        $stats = [
            'scanned' => $attempts->count(),
            'fulfilled' => 0,
            'already_placed' => 0,
            'not_paid' => 0,
            'failed' => 0,
        ];

        foreach ($attempts as $attempt) {
            $result = $this->fulfillment->fulfill(
                (string) $attempt->transaction_id,
                PalPlussFulfillmentService::SOURCE_RECONCILIATION,
                (string) $attempt->payment_request_id
            );

            $outcome = (string) ($result['outcome'] ?? 'failed');
            match ($outcome) {
                'order_placed' => $stats['fulfilled']++,
                'already_placed' => $stats['already_placed']++,
                'not_paid' => $stats['not_paid']++,
                default => $stats['failed']++,
            };
        }

        Log::info('palpluss.reconcile_complete', $stats);

        return $stats;
    }
}
