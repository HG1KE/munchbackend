<?php

namespace App\Console\Commands;

use App\Services\Paystack\PaystackUnverifiedReconciliationService;
use Illuminate\Console\Command;

class ReconcileUnverifiedPaystackCommand extends Command
{
    protected $signature = 'paystack:reconcile-unverified
                            {--min-age=5 : Minimum minutes since session created}
                            {--limit=50 : Max sessions per run}
                            {--reference= : Reconcile a single transaction reference}';

    protected $description = 'Verify Paystack order sessions and place missing orders (safety net for browser/webhook gaps)';

    public function handle(PaystackUnverifiedReconciliationService $reconciliation): int
    {
        $reference = $this->option('reference');
        $reference = is_string($reference) && $reference !== '' ? trim($reference) : null;

        $result = $reconciliation->reconcile(
            (int) $this->option('min-age') ?: (int) config('paystack.reconcile_min_age_minutes', 5),
            (int) $this->option('limit') ?: (int) config('paystack.reconcile_batch_limit', 50),
            $reference,
        );

        $this->info(sprintf(
            'Scanned %d — verified %d, orders placed %d, order failed %d, verify failed %d, not paid %d, skipped %d',
            $result['scanned'],
            $result['verified'],
            $result['orders_placed'],
            $result['order_failed'],
            $result['verify_failed'] ?? 0,
            $result['not_paid'],
            $result['skipped'],
        ));

        foreach ($result['details'] as $row) {
            $this->line(sprintf(
                '  %s — %s%s',
                $row['transaction_reference'] ?? $row['payment_request_id'] ?? '?',
                $row['result'] ?? 'unknown',
                isset($row['order_id']) ? ' (order #' . $row['order_id'] . ')' : ''
            ));
        }

        // A completed batch must not fail the scheduler when individual sessions
        // report order_failed or verify_failed — CRITICAL paid_without_order alerts
        // and ops tooling already surface genuine paid placement failures.
        return self::SUCCESS;
    }
}
