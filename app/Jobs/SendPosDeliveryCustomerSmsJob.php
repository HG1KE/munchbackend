<?php

namespace App\Jobs;

use App\CentralLogics\PosDeliveryCustomerSms;
use App\Model\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Customer SMS after a POS Delivery order is already committed.
 * Must never run inside the place-order HTTP response.
 */
class SendPosDeliveryCustomerSmsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $orderId)
    {
    }

    public function handle(): void
    {
        $order = Order::query()->find($this->orderId);
        if (! $order) {
            return;
        }

        try {
            PosDeliveryCustomerSms::dispatch($order);
        } catch (\Throwable $e) {
            Log::warning('pos_delivery_sms.job_exception', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('pos_delivery_sms.job_failed', [
            'order_id' => $this->orderId,
            'error' => $e->getMessage(),
        ]);
    }
}