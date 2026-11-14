<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingSoldDetails extends Model
{
    use HasFactory;

    protected $table = 'listing_sold_details';

    protected $fillable = [
        'listing_id',
        'anticipated_closed_date',
        'closed_date',
        'sales_price',
        'sellers_concessions',
        'amount',
        'finance_type',
        'agent',
        'agent_name',
        'company',
        'purchase_agreement',
        'closing_document',
    ];

    protected function casts(): array
    {
        return [
            'anticipated_closed_date' => 'datetime',
            'closed_date' => 'datetime',
            'sales_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'sellers_concessions' => 'integer',
            'agent' => 'integer',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}

