<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'role_name',
        'description',
        'price',
        'duration_days',
        'sort_order',
        'is_active',
        'max_listing_processes',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_days' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'max_listing_processes' => 'integer',
        ];
    }

    public function agentPackages(): HasMany
    {
        return $this->hasMany(AgentPackage::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
