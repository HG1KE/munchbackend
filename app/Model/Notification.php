<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
class Notification extends Model
{
    protected $casts = [
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function scopeActive($query)
    {
        return $query->where('status', '=', 1);
    }

    public function getImageFullPathAttribute(): string
    {
        $image = $this->image ?? null;

        if (! empty($image)) {
            return asset('storage/app/public/notification/' . $image);
        }

        return asset('public/assets/admin/img/icons/upload_img2.png');
    }
}
