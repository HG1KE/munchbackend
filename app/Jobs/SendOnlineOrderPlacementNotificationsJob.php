<?php

namespace App\Jobs;

use App\CentralLogics\OnlineOrderPlacementNotifications;
use App\Model\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SMS / email / push after an online order is already committed.
 * Must never run inside the place-order HTTP response.
 * Failure must not recreate or roll back the order.
 */
class SendOnlineOrderPlacementNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $orderId,
        public ?int $guestId = null,
        public ?int $authenticatedUserId = null,
    ) {
    }

    public function handle(): void
    {
        $order = Order::query()->find($this->orderId);
        if (! $order) {
            return;
        }

        try {
            OnlineOrderPlacementNotifications::send($order, $this->guestId, $this->authenticatedUserId);
        } catch (Throwable $e) {
            Log::warning('online_order.notification_job_exception', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning('online_order.notification_job_failed', [
            'order_id' => $this->orderId,
            'error' => $e->getMessage(),
        ]);
    }
}
