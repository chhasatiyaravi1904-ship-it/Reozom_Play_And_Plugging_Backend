<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingOfferAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'listing_offer_id',
        'user_id',
        'actor_type',
        'action_type',
        'offer_amount',
        'deadline',
        'terms_snapshot',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'offer_amount' => 'decimal:2',
            'deadline' => 'datetime',
            'terms_snapshot' => 'array',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(ListingOffer::class, 'listing_offer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
