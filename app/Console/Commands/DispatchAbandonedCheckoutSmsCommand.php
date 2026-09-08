<?php

namespace App\Console\Commands;

use App\CentralLogics\AbandonedCheckoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class DispatchAbandonedCheckoutSmsCommand extends Command
{
    protected $signature = 'sms:dispatch-abandoned-checkouts';

    protected $description = 'Find due abandoned checkouts and send recovery SMS (inline or queued).';

    public function handle(): int
    {
        $stats = AbandonedCheckoutService::dispatchDue();

        $this->line(sprintf(
            'Abandoned checkout SMS: claimed=%d sent=%d queued=%d skipped=%d (via_queue=%s)',
            $stats['claimed'],
            $stats['sent'],
            $stats['queued'],
            $stats['skipped'],
            config('abandoned_checkout.send_via_queue') ? 'yes' : 'no'
        ));

        if ($stats['queued'] > 0 && config('abandoned_checkout.send_via_queue')) {
            $this->drainSmsRecoveryQueue();
        }

        return self::SUCCESS;
    }

    private function drainSmsRecoveryQueue(): void
    {
        $maxSeconds = max(10, (int) config('abandoned_checkout.drain_queue_seconds', 50));

        try {
            Artisan::call('queue:work', [
                '--queue' => 'sms-recovery',
                '--stop-when-empty' => true,
                '--max-time' => $maxSeconds,
                '--sleep' => 1,
                '--tries' => 3,
            ]);
            $output = trim(Artisan::output());
            if ($output !== '') {
                $this->line($output);
            }
        } catch (\Throwable $e) {
            Log::warning('abandoned_cart.queue_drain_failed', [
                'error' => $e->getMessage(),
            ]);
            $this->warn('Queue drain failed: '.$e->getMessage());
        }
    }
}
