<?php

namespace App\Models;

use App\Model\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Cuisine extends Model
{
    use HasFactory;

    protected $casts =[
        'name' => 'string',
        'is_active' => 'integer',
        'priority' => 'integer',
    ];

    public function scopeActive($query){
        return $query->where('is_active', 1);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'cuisine_product', 'cuisine_id', 'product_id');
    }

    public function getImageFullPathAttribute(): string
    {
        $image = $this->image ?? null;

        if (! empty($image)) {
            return asset('storage/app/public/cuisine/' . $image);
        }

        return asset('public/assets/admin/img/160x160/img2.jpg');
    }

    public function translations(): MorphMany
    {
        return $this->morphMany('App\Model\Translation', 'translationable');
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
