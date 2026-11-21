<?php

namespace App\Models;

use App\Models\Concerns\SyncsToStripe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use HasFactory, HasUuids, SyncsToStripe;

    public function stripePlanType(): string
    {
        return 'agent_package';
    }

    protected $fillable = [
        'name',
        'slug',
        'role_name',
        'description',
        'included_features',
        'excluded_features',
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
            'included_features' => 'array',
            'excluded_features' => 'array',
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
