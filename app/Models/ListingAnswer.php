<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListingAnswer extends Model
{
    protected $fillable = [
        'listing_id',
        'step_id',
        'values',
    ];

    protected $casts = [
        'values' => 'array',
    ];

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }
}
