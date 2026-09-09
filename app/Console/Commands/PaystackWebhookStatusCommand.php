<?php

namespace App\Console\Commands;

use App\Services\Paystack\PaystackConfigResolver;
use Illuminate\Console\Command;

class PaystackWebhookStatusCommand extends Command
{
    protected $signature = 'paystack:webhook-status';

    protected $description = 'Show the Paystack webhook URL that must be configured in the Dashboard';

    public function handle(PaystackConfigResolver $config): int
    {
        $url = $config->webhookAbsoluteUrl();

        $this->line('Paystack Dashboard webhook URL (Settings → API Keys & Webhooks):');
        $this->info($url);

        return self::SUCCESS;
    }
}
