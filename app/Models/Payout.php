<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agent withdrawal. New ones are manual: the agent requests it, an admin pays it by bank
 * transfer and marks it paid (or rejects it). Older ones were Stripe Connect payouts.
 */
class Payout extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_REJECTED = 'rejected';

    public const METHOD_MANUAL = 'manual';
    public const METHOD_STRIPE = 'stripe';

    protected $fillable = [
        'agent_id', 'method', 'stripe_account_id', 'stripe_payout_id', 'amount', 'currency', 'bank_details',
        'status', 'reference', 'failure_message', 'arrival_date', 'paid_at', 'processed_by', 'processed_at',
    ];

    protected $hidden = ['bank_details'];

    protected $casts = [
        'amount' => 'integer',
        'bank_details' => 'encrypted:array',
        'arrival_date' => 'date',
        'paid_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
