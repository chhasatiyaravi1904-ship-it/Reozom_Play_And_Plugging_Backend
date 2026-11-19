<?php

namespace App\Models;

use App\Models\Concerns\SyncsToStripe;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServicePackage extends Model
{
    use HasFactory, SyncsToStripe;

    public function stripePlanType(): string
    {
        return 'service_package';
    }

    /** Agent plans carry the agent ID so they can be found in the Stripe dashboard. */
    public function stripeMetadata(): array
    {
        return ['plan_type' => 'service_package', 'plan_id' => (string) $this->id, 'agent_id' => (string) $this->agent_id];
    }

    /** Sync status for the agent's plan list (Retry button). */
    protected $appends = ['stripe_sync'];

    public function getStripeSyncAttribute(): array
    {
        return $this->stripeSyncSummary();
    }

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

    public function listings()
    {
        return $this->hasMany(Listing::class);
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
