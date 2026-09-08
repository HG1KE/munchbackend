<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
class Category extends Model
{
    protected $casts = [
        'parent_id' => 'integer',
        'position' => 'integer',
        'status' => 'integer',
        'priority' => 'integer'
    ];

    public function translations(): MorphMany
    {
        return $this->morphMany('App\Model\Translation', 'translationable');
    }

    public function scopeActive($query)
    {
        return $query->where('status', '=', 1);
    }

    public function childes(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function getNameAttribute($name)
    {
        if (auth('admin')->check() || auth('branch')->check()) {
            return $name;
        }
        return $this->translations[0]->value ?? $name;
    }

    public function getImageFullPathAttribute(): string
    {
        $image = $this->image ?? null;

        if (! empty($image)) {
            return asset('storage/app/public/category/' . $image);
        }

        return asset('public/assets/admin/img/160x160/img2.jpg');
    }

    public function getBannerImageFullPathAttribute(): string
    {
        $image = $this->banner_image ?? null;

        if (! empty($image)) {
            return asset('storage/app/public/category/banner/' . $image);
        }

        return asset('public/assets/admin/img/160x160/img2.jpg');
    }

    protected static function booted()
    {
        static::addGlobalScope('translate', function (Builder $builder) {
            $builder->with(['translations' => function ($query) {
                return $query->where('locale', app()->getLocale());
            }]);
        });
    }
}
