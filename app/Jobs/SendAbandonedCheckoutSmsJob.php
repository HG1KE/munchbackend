<?php

namespace App\Jobs;

use App\CentralLogics\AbandonedCheckoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAbandonedCheckoutSmsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int Max retry attempts before failing the job. */
    public int $tries = 3;

    /** @var array<int,int> Backoff in seconds. */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $abandonedCheckoutId)
    {
        $this->onQueue('sms-recovery');
    }

    public function handle(): void
    {
        try {
            AbandonedCheckoutService::sendForRow($this->abandonedCheckoutId);
        } catch (\Throwable $e) {
            Log::warning('abandoned_cart.job_exception', [
                'id' => $this->abandonedCheckoutId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('abandoned_cart.job_failed', [
            'id' => $this->abandonedCheckoutId,
            'error' => $e->getMessage(),
        ]);

        $row = \App\Model\AbandonedCheckout::query()->find($this->abandonedCheckoutId);
        if ($row && $row->sms_sent_at === null) {
            $row->forceFill([
                'sms_processed_at' => now(),
                'last_error' => 'job_failed',
                'last_skip_reason' => 'job_failed',
            ])->save();
        }
    }
}
