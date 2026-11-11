<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockSaleDeduction extends Model
{
    protected $fillable = [
        'order_id',
        'branch_id',
        'product_id',
        'stock_item_id',
        'quantity',
        'sale_date',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'branch_id' => 'integer',
        'product_id' => 'integer',
        'stock_item_id' => 'integer',
        'quantity' => 'float',
        'sale_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }
}
