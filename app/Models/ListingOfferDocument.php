<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingOfferDocument extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'listing_offer_id',
        'user_id',
        'type',
        'original_name',
        'path',
        'mime_type',
        'size',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(ListingOffer::class, 'listing_offer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
