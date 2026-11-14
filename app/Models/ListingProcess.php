<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ListingProcess extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'status',
        'agent_id',
        'service_package_id',
        'assigned_zips',
        'config',
        'required_disclosures',
    ];

    protected $casts = [
        'assigned_zips' => 'array',
        'config' => 'array',
        'required_disclosures' => 'array',
    ];

    public function getRequiredDisclosures(): array
    {
        return $this->required_disclosures ?? ($this->config['required_disclosures'] ?? []);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function servicePackage()
    {
        return $this->belongsTo(ServicePackage::class);
    }
}
