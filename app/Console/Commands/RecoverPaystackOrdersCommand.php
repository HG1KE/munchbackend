<?php

namespace App\Console\Commands;

use App\Services\Paystack\PaystackPaidOrderRecoveryService;
use Illuminate\Console\Command;

class RecoverPaystackOrdersCommand extends Command
{
    protected $signature = 'paystack:recover-orders
                            {--min-age=2 : Minimum minutes since payment session created}
                            {--limit=25 : Max sessions per run}
                            {--reference= : Recover a single transaction reference}';

    protected $description = 'Retry order placement for Paystack payments marked paid without an order';

    public function handle(PaystackPaidOrderRecoveryService $recovery): int
    {
        $reference = $this->option('reference');
        $reference = is_string($reference) && $reference !== '' ? trim($reference) : null;

        $result = $recovery->recoverStuckSessions(
            (int) $this->option('min-age'),
            (int) $this->option('limit'),
            $reference,
        );

        $this->info(sprintf(
            'Scanned %d, recovered %d, still stuck %d, skipped %d',
            $result['scanned'],
            $result['recovered'],
            $result['still_stuck'],
            $result['skipped'] ?? 0,
        ));

        foreach ($result['details'] as $row) {
            $this->line(sprintf(
                '  %s — %s',
                $row['reference'] ?? $row['payment_id'],
                $row['result'] ?? 'unknown'
            ));
        }

        return $result['still_stuck'] > 0 ? 1 : self::SUCCESS;
    }
}
