<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
class Admin extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['admin_role_id'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(AdminRole::class, 'admin_role_id');
    }

    public function getImageFullPathAttribute(): string
    {
        $image = $this->image ?? null;

        if (! empty($image)) {
            return asset('storage/app/public/admin/' . $image);
        }

        return asset('public/assets/admin/img/400x400/img2.jpg');
    }

    public function getIdentityImageFullPathAttribute()
    {
        $value = $this->identity_image ?? [];
        $imageUrlArray = is_array($value) ? $value : json_decode($value, true);
        if (is_array($imageUrlArray)) {
            foreach ($imageUrlArray as $key => $item) {
                if (! empty($item)) {
                    $imageUrlArray[$key] = asset('storage/app/public/admin/' . $item);
                } else {
                    $imageUrlArray[$key] = asset('public/assets/admin/img/400x400/img2.jpg');
                }
            }
        }
        return $imageUrlArray;
    }
}
