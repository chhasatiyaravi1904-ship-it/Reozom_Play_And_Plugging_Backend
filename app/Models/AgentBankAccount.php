<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The bank account an admin pays an agent's withdrawals into (US: routing + account number).
 */
class AgentBankAccount extends Model
{
    public const TYPES = ['checking', 'savings'];

    protected $fillable = [
        'agent_id', 'account_holder_name', 'bank_name', 'routing_number',
        'account_number', 'account_last4', 'account_type',
    ];

    protected $hidden = ['routing_number', 'account_number'];

    protected $casts = [
        'routing_number' => 'encrypted',
        'account_number' => 'encrypted',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** What the agent sees: no full numbers. */
    public function masked(): array
    {
        return [
            'account_holder_name' => $this->account_holder_name,
            'bank_name' => $this->bank_name,
            'account_type' => $this->account_type,
            'account_last4' => $this->account_last4,
            'routing_last4' => substr((string) $this->routing_number, -4),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Full details, copied onto a withdrawal request for the admin who pays it. */
    public function snapshot(): array
    {
        return [
            'account_holder_name' => $this->account_holder_name,
            'bank_name' => $this->bank_name,
            'account_type' => $this->account_type,
            'routing_number' => $this->routing_number,
            'account_number' => $this->account_number,
        ];
    }
}
