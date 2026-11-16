<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyDisclosure extends Model
{
    use HasFactory;

    protected $table = 'property_disclosures';

    protected $fillable = [
        'code',
        'title',
        'state_code',
        'property_type',
        'category',
        'description',
        'order',
        'is_required',
        'is_active',
        'schema',
    ];

    protected function casts(): array
    {
        return [
            'schema' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForListing(Builder $query, Listing $listing): Builder
    {
        $listingState = method_exists($listing, 'getStateCode') ? $listing->getStateCode() : strtoupper($listing->state ?? '');

        $rawType = $listing->publicPropertyType();

        $propertyType = strtolower($rawType ?? 'residential');
        $types = [$propertyType, 'all'];

        if (in_array($propertyType, ['single_family', 'condo', 'multi_family', 'townhouse', 'residential'])) {
            $types[] = 'residential';
        }
        if (in_array($propertyType, ['vacant_land', 'land', 'lot'])) {
            $types[] = 'vacant_land';
        }

        return $query->where(function ($q) use ($listingState) {
            $q->whereNull('state_code')
              ->orWhere('state_code', '')
              ->orWhere('state_code', 'ALL')
              ->orWhere('state_code', $listingState);
        })->where(function ($q) use ($types) {
            $q->whereNull('property_type')
              ->orWhere('property_type', '')
              ->orWhereIn('property_type', array_unique($types));
        });
    }
}

