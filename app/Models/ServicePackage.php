<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServicePackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_id',
        'listing_process_id',
        'name',
        'description',
        'price',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function zipCodes()
    {
        return $this->belongsToMany(ZipCode::class, 'service_package_zip_code');
    }

    /**
     * The listing process sellers of this package go through (several packages may share one).
     */
    public function listingProcess()
    {
        return $this->belongsTo(ListingProcess::class);
    }
}
