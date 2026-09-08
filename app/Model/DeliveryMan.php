<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
class DeliveryMan extends Authenticatable
{
    use Notifiable;

    public function reviews(): HasMany
    {
        return $this->hasMany(DMReview::class, 'delivery_man_id');
    }

    public function rating(): HasMany
    {
        return $this->hasMany(DMReview::class)
            ->select(DB::raw('avg(rating) average, delivery_man_id'))
            ->groupBy('delivery_man_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'delivery_man_id');
    }

    public function getImageFullPathAttribute(): string
    {
        $image = $this->image ?? null;

        if (! empty($image)) {
            return asset('storage/app/public/delivery-man/' . $image);
        }

        return asset('public/assets/admin/img/160x160/img1.jpg');
    }

    public function getIdentityImageFullPathAttribute()
    {
        $value = $this->identity_image ?? [];
        $imageUrlArray = is_array($value) ? $value : json_decode($value, true);
        if (is_array($imageUrlArray)) {
            foreach ($imageUrlArray as $key => $item) {
                if (! empty($item)) {
                    $imageUrlArray[$key] = asset('storage/app/public/delivery-man/' . $item);
                } else {
                    $imageUrlArray[$key] = asset('public/assets/admin/img/160x160/img1.jpg');
                }
            }
        }
        return $imageUrlArray;
    }
}
