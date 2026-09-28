<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MunchOrderWebhookOutbox extends Model
{
    public const EVENT_ORDER_RINGING = 'order.ringing';

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected $table = 'munch_order_webhook_outbox';

    protected $fillable = [
        'event_type',
        'order_id',
        'event_key',
        'payload',
        'status',
        'attempt_count',
        'occurred_at',
        'last_attempted_at',
        'delivered_at',
        'last_http_status',
        'last_error',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'payload' => 'array',
        'attempt_count' => 'integer',
        'last_http_status' => 'integer',
        'occurred_at' => 'datetime',
        'last_attempted_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public static function eventKeyForOrder(int $orderId): string
    {
        return self::EVENT_ORDER_RINGING.':'.$orderId;
    }
}
