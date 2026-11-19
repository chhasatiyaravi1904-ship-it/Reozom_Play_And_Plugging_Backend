<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Stripe Checkout payment. Created as "pending" when checkout starts; only Stripe
 * (webhook or a server-side session check) moves it to "paid".
 */
class Payment extends Model
{
    public const TYPE_AGENT_PACKAGE = 'agent_package';
    public const TYPE_LISTING = 'listing';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REFUNDED = 'refunded';

    // Status, Stripe IDs and amounts are set by server code only, never mass-assigned from requests.
    protected $fillable = [
        'type',
        'user_id',
        'package_id',
        'agent_package_id',
        'listing_id',
        'service_package_id',
        'agent_id',
        'amount',
        'currency',
        'platform_fee',
        'processing_fee',
        'agent_amount',
        'destination_account_id',
        'stripe_refund_id',
        'description',
        'status',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'paid_at',
        'refunded_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'platform_fee' => 'integer',
        'processing_fee' => 'integer',
        'agent_amount' => 'integer',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** Amount in dollars (amount is stored in cents). */
    public function amountDecimal(): float
    {
        return $this->amount / 100;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function agentPackage(): BelongsTo
    {
        return $this->belongsTo(AgentPackage::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class);
    }
}
