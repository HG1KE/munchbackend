<?php

namespace App\Console\Commands;

use App\CentralLogics\ReorderReminderService;
use App\Jobs\SendReorderReminderSmsJob;
use App\Model\ReorderReminderLog;
use Illuminate\Console\Command;

class DispatchReorderReminderSmsCommand extends Command
{
    protected $signature = 'sms:dispatch-reorder-reminders';

    protected $description = 'Find eligible repeat customers and queue reorder reminder SMS jobs.';

    public function handle(): int
    {
        $count = ReorderReminderService::reap(function (ReorderReminderLog $log): void {
            SendReorderReminderSmsJob::dispatch((int) $log->id);
        });

        $this->line(sprintf('Dispatched %d reorder-reminder SMS job(s).', $count));

        return self::SUCCESS;
    }
}
