<?php

namespace App\Console\Commands;

use App\Services\Paystack\PaystackUnverifiedReconciliationService;
use Illuminate\Console\Command;

class AuditUnverifiedPaystackCommand extends Command
{
    protected $signature = 'paystack:audit-unverified
                            {--min-age=0 : Only include sessions at least this many minutes old}
                            {--limit=500 : Max rows to list}';

    protected $description = 'Report Paystack order payment sessions never marked verified (is_paid = 0)';

    public function handle(PaystackUnverifiedReconciliationService $reconciliation): int
    {
        $audit = $reconciliation->auditUnverified(
            (int) $this->option('min-age'),
            (int) $this->option('limit'),
        );

        $this->info('Paystack unverified order sessions audit');
        $this->line('Count: ' . $audit['count']);
        $this->line('Total payment value (KES): ' . number_format($audit['total_payment_value'], 2));
        $this->line('Oldest: ' . ($audit['oldest_at'] ?? '—'));

        if ($audit['references'] === []) {
            $this->comment('No unverified sessions found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Reference', 'Payment ID', 'Payer', 'Amount', 'Created'],
            array_map(static fn (array $row) => [
                $row['transaction_reference'],
                $row['payment_request_id'],
                $row['payer_id'],
                number_format((float) $row['payment_amount'], 2),
                $row['created_at'] ?? '—',
            ], $audit['references'])
        );

        return self::SUCCESS;
    }
}
