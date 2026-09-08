<?php

namespace App\Model;

use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyDeliverySmsLog extends Model
{
    protected $table = 'loyalty_delivery_sms_logs';

    protected $fillable = [
        'order_id',
        'user_id',
        'phone',
        'customer_name',
        'earned_points',
        'points_balance',
        'status',
        'skip_reason',
        'last_provider_status',
        'last_error',
        'message_body',
        'sms_sent_at',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'user_id' => 'integer',
        'earned_points' => 'integer',
        'points_balance' => 'integer',
        'sms_sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
