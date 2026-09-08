<?php

namespace App\Console\Commands;

use App\CentralLogics\OrderAutomationService;
use App\Model\OrderAutomationSetting;
use Illuminate\Console\Command;

class AutoCompleteEligibleOrdersCommand extends Command
{
    protected $signature = 'orders:auto-complete-eligible';

    protected $description = 'Mark eligible stale orders as delivered using the standard delivered transition pipeline';

    public function handle(): int
    {
        $settings = OrderAutomationSetting::current();

        if (! $settings->is_enabled) {
            $this->info('Order automation is disabled.');

            return self::SUCCESS;
        }

        $result = OrderAutomationService::run('scheduled', manualRun: false);

        $run = $result['run'];
        $this->info($result['message']);
        $this->line("Run #{$run->id}: eligible={$run->eligible_count}, completed={$run->completed_count}, skipped={$run->skipped_count}, failed={$run->failed_count}, dry_run=".($run->dry_run ? 'yes' : 'no'));

        return self::SUCCESS;
    }
}
