<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class StockManager extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'all_branches',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'all_branches' => 'boolean',
        'status' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'stock_manager_branch', 'stock_manager_id', 'branch_id')
            ->withTimestamps();
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    /**
     * Branches this manager may operate on (all active branches when all_branches is set).
     */
    public function accessibleBranches(): Collection
    {
        if ($this->all_branches) {
            return Branch::query()->orderBy('name')->get(['id', 'name']);
        }

        if ($this->relationLoaded('branches')) {
            return $this->branches->sortBy('name')->values();
        }

        return $this->branches()->orderBy('name')->get(['branches.id', 'branches.name']);
    }

    public function canAccessBranch(int $branchId): bool
    {
        if ($branchId <= 0) {
            return false;
        }

        if ($this->all_branches) {
            return Branch::query()->where('id', $branchId)->exists();
        }

        if ($this->relationLoaded('branches')) {
            return $this->branches->contains('id', $branchId);
        }

        return $this->branches()->where('branches.id', $branchId)->exists();
    }

    public function branchLabel(): string
    {
        if ($this->all_branches) {
            return translate('All branches');
        }

        $names = $this->relationLoaded('branches')
            ? $this->branches->pluck('name')
            : $this->branches()->orderBy('name')->pluck('name');

        if ($names->isEmpty()) {
            return '—';
        }

        return $names->implode(', ');
    }

    /**
     * @deprecated Kept for any residual single-branch reads; prefer accessibleBranches().
     */
    public function getBranchAttribute(): ?Branch
    {
        $branches = $this->accessibleBranches();
        /** @var Branch|null $first */
        $first = $branches instanceof EloquentCollection
            ? $branches->first()
            : collect($branches)->first();

        return $first instanceof Branch ? $first : null;
    }
}
