<?php

namespace App\Model;

use App\Models\OfflinePayment;
use App\Models\GuestUser;
use App\Models\OrderChangeAmount;
use App\Models\OrderPartialPayment;
use App\Services\OrderReadableIdService;
use App\Support\OnlineOrderStatus;
use App\Support\OrderDispatchedTime;
use App\Support\PosOrderTypes;
use App\Support\OrderPlacementTime;
use App\User;
use App\Models\OrderArea;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $appends = [
        'order_display_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (empty($order->readable_order_id)) {
                $order->readable_order_id = app(OrderReadableIdService::class)->reserveNextReadableId();
            }
            OrderPlacementTime::applyToOrder($order);
        });

        static::saving(function (Order $order) {
            try {
                if ($order->isDirty('order_status')
                    && (string) $order->order_status === OnlineOrderStatus::OUT_FOR_DELIVERY) {
                    OrderDispatchedTime::applyToOrder($order);
                }
            } catch (\Throwable) {
                // Never block order persistence from timer stamps.
            }
        });
    }

    public function getOrderDisplayIdAttribute(): string
    {
        return \App\CentralLogics\Helpers::order_display_id($this);
    }

    public function getPublicOrderNumberAttribute(): ?string
    {
        return $this->readable_order_id;
    }

    protected $casts = [
        'order_amount' => 'float',
        'coupon_discount_amount' => 'float',
        'total_tax_amount' => 'float',
        'total_add_on_tax' => 'float',
        'delivery_address_id' => 'integer',
        'delivery_man_id' => 'integer',
        'delivery_charge' => 'float',
        'user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'delivery_address' => 'array',
        'table_id' => 'integer',
        'number_of_people' => 'integer',
        'table_order_id' => 'integer',
        'is_cutlery_required' => 'integer',
        'bring_change_amount' => 'float',
        'referral_discount' => 'float',
        'customer_confirmed_sms_sent_at' => 'datetime',
        'customer_placement_sms_sent_at' => 'datetime',
        'customer_processing_sms_sent_at' => 'datetime',
        'kitchen_printed_at' => 'datetime',
        'receipt_printed_at' => 'datetime',
    ];

    public function details(): HasMany
    {
        return $this->hasMany(OrderDetail::class);
    }

    public function delivery_man(): BelongsTo
    {
        return $this->belongsTo(DeliveryMan::class, 'delivery_man_id')->withCount('orders');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withCount('orders');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id')->withCount('orders');
    }

    public function delivery_address(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'delivery_address_id');
    }

    public function customer_delivery_address(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'delivery_address_id');
    }

    public function table_order(): BelongsTo
    {
        return $this->belongsTo(TableOrder::class, 'table_order_id', 'id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class, 'table_id', 'id');
    }

    public function scopePos($query)
    {
        return $query->where(function ($inner) {
            $inner->where('order_type', 'pos');
            if (Schema::hasColumn($this->getTable(), 'sales_channel')) {
                $inner->orWhereIn('sales_channel', PosOrderTypes::salesChannels());
            }
        });
    }

    public function scopeDineIn($query)
    {
        return $query->where('order_type', '=', 'dine_in');
    }


    public function scopeNotDineIn($query)
    {
        return $query->where('order_type', '!=', 'dine_in');
    }

    public function scopeNotPos($query)
    {
        $query->where('order_type', '!=', 'pos');
        if (Schema::hasColumn($this->getTable(), 'sales_channel')) {
            $query->where(function ($inner) {
                $inner->whereNull('sales_channel')
                    ->orWhere('sales_channel', '')
                    ->orWhereNotIn('sales_channel', PosOrderTypes::salesChannels());
            });
        }

        return $query;
    }

    /**
     * Genuine website / app / API orders. Never POS Delivery or any other POS channel.
     */
    public function scopeOnlineOrders($query)
    {
        return $query->notPos()->notDineIn();
    }

    public function isPosFamily(): bool
    {
        return PosOrderTypes::isPosFamily($this->order_type ?? null, $this->sales_channel ?? null);
    }

    public function isPosDeliveryOrder(): bool
    {
        return PosOrderTypes::isPosDeliveryOrder($this->order_type ?? null, $this->sales_channel ?? null);
    }

    public function scopeSchedule($query)
    {
        return $query->whereDate('delivery_date', '>', \Carbon\Carbon::now()->format('Y-m-d'));
    }

    public function scopeNotSchedule($query)
    {
        return $query->whereDate('delivery_date', '<=', \Carbon\Carbon::now()->format('Y-m-d'));
    }

    public function scopeEarningReport($query)
    {
        return $query->whereIn('order_status', ['delivered', 'completed']);
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(OrderTransaction::class);
    }

    public function order_partial_payments(): HasMany
    {
        return $this->hasMany(OrderPartialPayment::class)->orderBy('id', 'DESC');
    }

    public function offline_payment()
    {
        return $this->hasOne(OfflinePayment::class, 'order_id');
    }

    public function scopePartial($query)
    {
        return $query->whereHas('partial_payment');
    }

    public function guest()
    {
        return $this->belongsTo(GuestUser::class, 'user_id');
    }

    public function deliveryman_review()
    {
        return $this->hasOne(DMReview::class, 'order_id');
    }

    public function order_area()
    {
        return $this->hasOne(OrderArea::class, 'order_id');
    }

    public function order_change_amount()
    {
        return $this->hasOne(OrderChangeAmount::class, 'order_id');
    }
}
