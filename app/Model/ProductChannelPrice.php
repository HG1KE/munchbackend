<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductChannelPrice extends Model
{
    protected $table = 'product_channel_prices';

    protected $fillable = [
        'product_id',
        'branch_id',
        'channel',
        'price',
        'is_available',
    ];

    protected $casts = [
        'id' => 'integer',
        'product_id' => 'integer',
        'branch_id' => 'integer',
        'is_available' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function hasPriceOverride(): bool
    {
        return $this->price !== null;
    }
}
