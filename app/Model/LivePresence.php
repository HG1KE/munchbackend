<?php

namespace App\Model;

use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivePresence extends Model
{
    public const STATE_BROWSING = 'browsing';

    public const STATE_CART_ACTIVE = 'cart_active';

    public const STATE_CHECKOUT = 'checkout';

    protected $table = 'live_presence';

    protected $fillable = [
        'client_token',
        'user_id',
        'branch_id',
        'current_state',
        'current_path',
        'last_seen_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'branch_id' => 'integer',
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
