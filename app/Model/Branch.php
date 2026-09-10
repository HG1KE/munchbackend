<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\DeliveryChargeSetup;
use App\Models\DeliveryChargeByArea;


class Branch extends Authenticatable
{
    use Notifiable;

    protected $casts = [
        'coverage' => 'integer',
        'status' => 'integer',
        'branch_promotion_status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'preparation_time' => 'integer',
        'pos_mpesa_enabled' => 'integer',
    ];

    public function branch_promotion(): HasMany
    {
        return $this->hasMany(BranchPromotion::class);
    }

    public function table(): HasMany
    {
        return $this->hasMany(Table::class, 'branch_id', 'id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function getImageFullPathAttribute(): string
    {
        $image = $this->image ?? null;

        if (! empty($image)) {
            return asset('storage/app/public/branch/' . $image);
        }

        return asset('public/assets/admin/img/160x160/img2.jpg');
    }

    public function getCoverImageFullPathAttribute(): string
    {
        $image = $this->cover_image ?? null;

        if (! empty($image)) {
            return asset('storage/app/public/branch/' . $image);
        }

        return asset('public/assets/admin/img/160x160/img2.jpg');
    }

    public function delivery_charge_setup()
    {
        return $this->hasOne(DeliveryChargeSetup::class, 'branch_id', 'id');
    }
    public function delivery_charge_by_area()
    {
        return $this->hasMany(DeliveryChargeByArea::class, 'branch_id', 'id')->latest();
    }

    public function branch_time_schedules(): HasMany
    {
        return $this->hasMany(BranchTimeSchedule::class, 'branch_id', 'id');
    }

}
