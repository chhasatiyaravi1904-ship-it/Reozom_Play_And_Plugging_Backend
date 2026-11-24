<?php

namespace App\Models;

use Carbon\Carbon;
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

    // Reozom Numeric Status Constants
    const STATUS_OPEN = 0;
    const STATUS_ACCEPTED_BY_SELLER = 1;
    const STATUS_CANCELED_BY_BUYER = 2;
    const STATUS_DECLINED_BY_SELLER = 3;
    const STATUS_COUNTEROFFER_SENT_BY_BUYER = 4;
    const STATUS_COUNTEROFFER_SENT_BY_SELLER = 5;
    const STATUS_COUNTEROFFER_ACCEPTED_BY_BUYER = 6;
    const STATUS_COUNTEROFFER_ACCEPTED_BY_SELLER = 7;
    const STATUS_COUNTEROFFER_CANCELED_BY_SELLER = 8;
    const STATUS_COUNTEROFFER_DECLINED_BY_SELLER = 9;
    const STATUS_COUNTEROFFER_DECLINED_BY_BUYER = 10;
    const STATUS_COUNTEROFFER_CANCELED_BY_BUYER = 11;
    const STATUS_OFFER_EXPIRED_BY_DEADLINE_NO_RESPONSE_FROM_SELLER = 12;
    const STATUS_OFFER_EXPIRED_BY_DEADLINE_NO_RESPONSE_FROM_BUYER = 13;
    const STATUS_CLOSED = 14;

    // String Status Constants for API and Client State
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
        'direct_buyer_rebate',
        'earnest_money_deposit',
        'down_payment_amount',
        'payment_method',
        'closing_date',
        'occupancy_type',
        'occupancy_days',
        'seller_concessions',
        'closing_amount_type',
        'fee_per_day',
        'commission',
        'commission_type',
        'home_warranty_included',
        'home_warranty_price',
        'buyers_home_address_under_contract',
        'mortgage_application_provided_by',
        'loan_approval_provided_by',
        'contingencies',
        'appliances',
        'other_appliances',
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

    protected $appends = [
        'bottom_line_offer',
        'percentage',
        'full_bottom_line_offer',
        'full_percentage',
    ];

    protected function casts(): array
    {
        return [
            'offer_amount' => 'decimal:2',
            'direct_buyer_rebate' => 'decimal:2',
            'earnest_money_deposit' => 'decimal:2',
            'down_payment_amount' => 'decimal:2',
            'seller_concessions' => 'decimal:2',
            'fee_per_day' => 'decimal:2',
            'commission' => 'decimal:2',
            'home_warranty_price' => 'decimal:2',
            'occupancy_days' => 'integer',
            'closing_amount_type' => 'integer',
            'commission_type' => 'integer',
            'home_warranty_included' => 'boolean',
            'is_backup_offer' => 'boolean',
            'has_agent_representation' => 'boolean',
            'contingencies' => 'array',
            'appliances' => 'array',
            'closing_date' => 'date',
            'mortgage_application_provided_by' => 'date',
            'loan_approval_provided_by' => 'date',
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

    public function purchasers(): HasMany
    {
        return $this->hasMany(ListingOfferPurchaser::class);
    }

    /**
     * Purchase agreement signers in the original Reozom order (OffersService::sendPurchaseAgreementOutForSignature):
     * listing agent (when an agent owns the listing), selling agent (when an agent sent the offer),
     * buyer (when not an agent), the other purchasers, the seller (titled owner), then the listing's
     * additional signers. Duplicate emails are dropped; the order matches the $sN tags on the PDF.
     *
     * @return array<int, array{name: string, email: string, role: string}>
     */
    public function eSignSigners(): array
    {
        $this->loadMissing(['listing.user', 'buyer', 'purchasers']);

        $signers = [];
        $add = function (?string $name, ?string $email, string $role) use (&$signers) {
            $email = strtolower(trim((string) $email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || isset($signers[$email])) {
                return;
            }
            $signers[$email] = ['name' => trim((string) $name) ?: $email, 'email' => $email, 'role' => $role];
        };
        $fullName = fn (?User $u) => $u ? (trim("{$u->first_name} {$u->last_name}") ?: (string) $u->name) : null;

        $listingUser = $this->listing?->user;
        if ($listingUser?->isAgent()) {
            $add($fullName($listingUser), $listingUser->email, 'listing_agent');
        }

        if ($this->buyer?->isAgent()) {
            $add($fullName($this->buyer), $this->buyer->email, 'selling_agent');
        } elseif ($this->buyer) {
            $add($fullName($this->buyer), $this->buyer->email, 'buyer');
        }

        foreach ($this->purchasers as $purchaser) {
            $add(trim("{$purchaser->first_name} {$purchaser->last_name}"), $purchaser->email, 'buyer');
        }

        foreach ($this->listing?->ownerSigners() ?? [] as $index => $owner) {
            $add($owner['name'], $owner['email'], $index === 0 ? 'seller' : 'additional_signer');
        }

        return array_values($signers);
    }

    /**
     * The user's own entry among a purchase agreement's (or addendum's) signers: matched by email,
     * or the seller signer for the listing owner's account, whose login email can differ from the
     * owner email entered in the listing. Null when the user does not sign.
     *
     * @param  array<int, array<string, mixed>>  $signers
     */
    public function signerFor(User $user, array $signers): ?array
    {
        $email = strtolower((string) $user->email);
        foreach ($signers as $signer) {
            if (strtolower((string) ($signer['email'] ?? '')) === $email) {
                return $signer;
            }
        }

        if ($user->id === $this->listing?->user_id) {
            foreach ($signers as $signer) {
                if (($signer['role'] ?? null) === 'seller') {
                    return $signer;
                }
            }
        }

        return null;
    }

    /**
     * Get the offer's bottom line offer calculated from offer amount minus closing costs / seller concessions.
     * Replicates Reozom: ListingOffer::getBottomLineOfferAttribute
     */
    public function getBottomLineOfferAttribute(): float
    {
        $offerAmount = (float) $this->offer_amount;
        $concessions = (float) $this->seller_concessions;

        if ((int) $this->closing_amount_type === 1) { // Percentage
            return round($offerAmount - ($offerAmount * ($concessions / 100)), 2);
        }

        return round(max(0, $offerAmount - $concessions), 2);
    }

    /**
     * Get bottom line percentage relative to proposed purchase price.
     * Replicates Reozom: ListingOffer::getPercentageAttribute
     */
    public function getPercentageAttribute(): float
    {
        $offerAmount = (float) $this->offer_amount;
        if ($offerAmount <= 0) {
            return 100.0;
        }

        return round(($this->bottom_line_offer * 100) / $offerAmount, 2);
    }

    /**
     * Full bottom line deducting agent commission if applicable.
     * Replicates Reozom: ListingOffer::getFullBottomLineOfferAttribute
     */
    public function getFullBottomLineOfferAttribute(): float
    {
        $bottomLine = $this->bottom_line_offer;

        if (!$this->has_agent_representation && (float) $this->commission <= 0) {
            return $bottomLine;
        }

        $commission = (float) $this->commission;
        if ((int) $this->commission_type === 1) { // %
            return round($bottomLine - ($commission * ($bottomLine / 100)), 2);
        }

        return round(max(0, $bottomLine - $commission), 2);
    }

    /**
     * Full percentage including commission deduction.
     * Replicates Reozom: ListingOffer::getFullPercentageAttribute
     */
    public function getFullPercentageAttribute(): float
    {
        $offerAmount = (float) $this->offer_amount;
        if ($offerAmount <= 0) {
            return 100.0;
        }

        return round(($this->full_bottom_line_offer * 100) / $offerAmount, 2);
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
}
