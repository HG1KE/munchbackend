<?php

namespace App\Model;

use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReorderReminderLog extends Model
{
    protected $table = 'reorder_reminder_logs';

    protected $casts = [
        'user_id' => 'integer',
        'branch_id' => 'integer',
        'last_delivered_order_id' => 'integer',
        'last_order_total' => 'float',
        'last_order_date' => 'date',
        'attempt_number' => 'integer',
        'sms_attempts' => 'integer',
        'sms_sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function lastOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'last_delivered_order_id');
    }
}
