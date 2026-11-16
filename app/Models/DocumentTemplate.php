<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DocumentTemplate extends Model
{
    public const SOURCE_DISCLOSURE = 'disclosure';

    public const SOURCE_STATIC = 'static';

    protected $fillable = [
        'code',
        'name',
        'state_code',
        'property_type',
        'source',
        'disclosure_codes',
        'rules',
        'body',
        'requires_signature',
        'order',
        'is_active',
    ];

    protected $casts = [
        'disclosure_codes' => 'array',
        'rules' => 'array',
        'requires_signature' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Templates for a state and property group ('residential' or 'vacant_land').
     */
    public function scopeFor(Builder $query, ?string $stateCode, string $propertyGroup): Builder
    {
        return $query
            ->where(fn ($q) => $q->whereNull('state_code')->orWhere('state_code', 'ALL')->orWhere('state_code', strtoupper((string) $stateCode)))
            ->where(fn ($q) => $q->whereNull('property_type')->orWhere('property_type', 'all')->orWhere('property_type', $propertyGroup));
    }
}
