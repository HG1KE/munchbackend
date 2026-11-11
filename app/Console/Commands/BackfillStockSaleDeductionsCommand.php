<?php

namespace App\Console\Commands;

use App\Services\Stock\StockDeductionService;
use Illuminate\Console\Command;

class BackfillStockSaleDeductionsCommand extends Command
{
    protected $signature = 'stocks:backfill-deductions
                            {--since= : Sale date (Y-m-d, app TZ) to start from; default today}
                            {--branch= : Optional branch_id filter}
                            {--dry-run : Count candidate orders without writing}';

    protected $description = 'Idempotently rebuild stock_sale_deductions for recent sold orders (safe to re-run)';

    public function handle(StockDeductionService $service): int
    {
        $since = (string) ($this->option('since') ?: now()->toDateString());
        $branchOpt = $this->option('branch');
        $branchId = $branchOpt !== null && $branchOpt !== '' ? (int) $branchOpt : null;

        if ($this->option('dry-run')) {
            $this->warn('Dry-run is informational only; use without --dry-run to write.');
            $this->line("Would backfill since {$since}" . ($branchId ? " branch={$branchId}" : ' (all branches)'));
            $this->line('Sold statuses: ' . implode(', ', StockDeductionService::SOLD_STATUSES));
            $this->line('Sold scope: POS/Branch POS only (excludes pure online web orders to avoid double-count).');

            return self::SUCCESS;
        }

        $this->info("Backfilling stock sale deductions since {$since}" . ($branchId ? " branch={$branchId}" : ''));
        $this->line('Sold scope: POS/Branch POS only (online web orders clear/skip).');
        $result = $service->backfillSince($since, $branchId);
        $this->line(sprintf(
            'scanned=%d synced=%d with_rows=%d cleared=%d',
            $result['scanned'],
            $result['synced'],
            $result['with_rows'],
            $result['cleared']
        ));

        return self::SUCCESS;
    }
}
