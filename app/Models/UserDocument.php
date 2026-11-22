<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document in a user's own library (original Reozom AllDocument): uploaded outside a listing,
 * or shared with the user, such as a signed addendum.
 */
class UserDocument extends Model
{
    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_ADDENDUM = 'addendum';

    protected $fillable = [
        'user_id',
        'listing_id',
        'name',
        'original_name',
        'path',
        'mime_type',
        'size',
        'source',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
