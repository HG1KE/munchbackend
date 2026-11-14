<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariationStockAllocation extends Model
{
    protected $fillable = [
        'product_id',
        'option_key',
        'group_name',
        'option_label',
        'stock_item_id',
        'quantity',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'stock_item_id' => 'integer',
        'quantity' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}
