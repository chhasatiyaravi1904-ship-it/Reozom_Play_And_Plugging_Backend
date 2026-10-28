<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingEunoAgreement extends Model
{
    use HasFactory;

    protected $table = 'listing_euno_agreements';

    protected $fillable = [
        'listing_id',
        'listing_offer_id',
        'initiated_by_user_id',
        'document_type',
        'document_name',
        'agreement_id',
        'signing_url',
        'status',
        'signers',
        'coordinates',
        'source_file_paths',
        'signed_file_path',
        'audit_file_path',
        'sent_at',
        'completed_at',
    ];

    protected $casts = [
        'signers' => 'array',
        'coordinates' => 'array',
        'source_file_paths' => 'array',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function listingOffer(): BelongsTo
    {
        return $this->belongsTo(ListingOffer::class);
    }

    public function initiatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
