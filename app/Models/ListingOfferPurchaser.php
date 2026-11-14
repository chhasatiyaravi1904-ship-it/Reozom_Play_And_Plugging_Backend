<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingOfferPurchaser extends Model
{
    use HasFactory;

    protected $table = 'listing_offer_purchasers';

    protected $fillable = [
        'listing_offer_id',
        'first_name',
        'last_name',
        'email',
        'street_address',
        'city',
        'unit_designator',
        'unit_number',
        'state_id',
        'zip',
        'same_address',
        'non_us_citizen',
        'is_main',
    ];

    protected function casts(): array
    {
        return [
            'same_address' => 'integer',
            'non_us_citizen' => 'integer',
            'is_main' => 'boolean',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(ListingOffer::class, 'listing_offer_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}

