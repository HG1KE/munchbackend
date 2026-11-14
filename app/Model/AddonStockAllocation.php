<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddonStockAllocation extends Model
{
    protected $fillable = [
        'addon_id',
        'stock_item_id',
        'quantity',
    ];

    protected $casts = [
        'addon_id' => 'integer',
        'stock_item_id' => 'integer',
        'quantity' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function addon(): BelongsTo
    {
        return $this->belongsTo(AddOn::class, 'addon_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}
