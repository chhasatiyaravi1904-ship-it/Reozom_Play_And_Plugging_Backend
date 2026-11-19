<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agent withdrawal: a Stripe payout from the agent's Connect account to their bank.
 */
class Payout extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'agent_id', 'stripe_account_id', 'stripe_payout_id', 'amount', 'currency',
        'status', 'failure_message', 'arrival_date', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'arrival_date' => 'date',
        'paid_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
