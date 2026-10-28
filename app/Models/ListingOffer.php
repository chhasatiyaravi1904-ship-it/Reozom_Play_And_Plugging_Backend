<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ListingOffer extends Model
{
    use HasFactory, SoftDeletes;

    const STATUS_SUBMITTED = 'submitted';
    const STATUS_COUNTERED_BY_SELLER = 'countered_by_seller';
    const STATUS_COUNTERED_BY_BUYER = 'countered_by_buyer';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';
    const STATUS_WITHDRAWN = 'withdrawn';
    const STATUS_EXPIRED = 'expired';
    const STATUS_REJECTED_UNDER_CONTRACT = 'rejected_under_contract';

    protected $fillable = [
        'listing_id',
        'buyer_id',
        'reference_code',
        'status',
        'offer_amount',
        'earnest_money_deposit',
        'down_payment_amount',
        'payment_method',
        'closing_date',
        'occupancy_type',
        'occupancy_days',
        'seller_concessions',
        'contingencies',
        'deadline',
        'additional_terms',
        'is_backup_offer',
        'buyer_name',
        'buyer_email',
        'buyer_phone',
        'has_agent_representation',
        'agent_name',
        'agent_email',
        'agent_phone',
    ];

    protected function casts(): array
    {
        return [
            'offer_amount' => 'decimal:2',
            'earnest_money_deposit' => 'decimal:2',
            'down_payment_amount' => 'decimal:2',
            'seller_concessions' => 'decimal:2',
            'occupancy_days' => 'integer',
            'is_backup_offer' => 'boolean',
            'has_agent_representation' => 'boolean',
            'contingencies' => 'array',
            'closing_date' => 'date',
            'deadline' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ListingOffer $offer) {
            $offer->reference_code ??= 'OFR-'.strtoupper(Str::random(6));
        });
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ListingOfferAction::class)->orderBy('created_at', 'asc');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ListingOfferDocument::class);
    }

    public function isExpired(): bool
    {
        return $this->deadline && $this->deadline->isPast() && in_array($this->status, [
            self::STATUS_SUBMITTED,
            self::STATUS_COUNTERED_BY_SELLER,
            self::STATUS_COUNTERED_BY_BUYER,
        ]);
    }

    public function isPendingResponse(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUBMITTED,
            self::STATUS_COUNTERED_BY_SELLER,
            self::STATUS_COUNTERED_BY_BUYER,
        ]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_SUBMITTED,
            self::STATUS_COUNTERED_BY_SELLER,
            self::STATUS_COUNTERED_BY_BUYER,
        ]);
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACCEPTED);
    }
}
