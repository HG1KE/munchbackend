<?php

namespace App\Services;

use App\Model\Order;
use App\Model\OrderCancellationAuditLog;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;

class OrderCancellationAuditLogger
{
    public function record(
        Order $order,
        string $previousStatus,
        string $newStatus,
        string $reason,
        string $actorType,
        ?int $actorId,
        string $source = 'pos'
    ): void {
        if (! Schema::hasTable('order_cancellation_audit_logs')) {
            return;
        }

        OrderCancellationAuditLog::query()->create([
            'order_id' => (int) $order->id,
            'branch_id' => $order->branch_id ? (int) $order->branch_id : null,
            'actor_type' => $actorType !== '' ? $actorType : 'branch',
            'actor_id' => $actorId,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'reason' => $reason,
            'source' => $source,
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);
    }
}
