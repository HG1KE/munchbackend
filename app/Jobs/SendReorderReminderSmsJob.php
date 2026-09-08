<?php

namespace App\Jobs;

use App\CentralLogics\ReorderReminderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendReorderReminderSmsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $reorderReminderLogId)
    {
        $this->onQueue('sms-recovery');
    }

    public function handle(): void
    {
        try {
            ReorderReminderService::sendForRow($this->reorderReminderLogId);
        } catch (\Throwable $e) {
            Log::warning('reorder_reminder.job_exception', [
                'id' => $this->reorderReminderLogId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('reorder_reminder.job_failed', [
            'id' => $this->reorderReminderLogId,
            'error' => $e->getMessage(),
        ]);
    }
}
