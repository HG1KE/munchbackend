<?php

namespace App\Console\Commands;

use App\Services\PalPluss\PalPlussReconciliationService;
use Illuminate\Console\Command;

class PalPlussReconcileCommand extends Command
{
    protected $signature = 'palpluss:reconcile-unverified
                            {--transaction= : Optional PalPluss transaction UUID to fulfill directly}';

    protected $description = 'Reconcile PalPluss STK payments that succeeded but were not fulfilled (including unmatched SUCCESS orphans)';

    public function handle(PalPlussReconciliationService $service): int
    {
        $transaction = $this->option('transaction');
        $stats = $service->reconcile(is_string($transaction) ? $transaction : null);
        $this->info(json_encode($stats));

        return self::SUCCESS;
    }
}
