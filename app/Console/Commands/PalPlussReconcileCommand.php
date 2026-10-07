<?php

namespace App\Console\Commands;

use App\Services\PalPluss\PalPlussReconciliationService;
use Illuminate\Console\Command;

class PalPlussReconcileCommand extends Command
{
    protected $signature = 'palpluss:reconcile-unverified';

    protected $description = 'Reconcile PalPluss STK payments that succeeded but were not fulfilled';

    public function handle(PalPlussReconciliationService $service): int
    {
        $stats = $service->reconcile();
        $this->info(json_encode($stats));

        return self::SUCCESS;
    }
}
