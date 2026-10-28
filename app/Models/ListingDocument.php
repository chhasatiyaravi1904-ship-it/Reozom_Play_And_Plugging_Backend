<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingDocument extends Model
{
    use HasUuids;

    protected $fillable = [
        'listing_id',
        'user_id',
        'type',
        'original_name',
        'path',
        'mime_type',
        'size',
        'status',
        'reviewed_by',
        'reviewed_at',
        'admin_notes',
        'signed_at',
        'signed_by',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
