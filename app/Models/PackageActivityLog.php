<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageActivityLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'package_id',
        'previous_package_id',
        'action',
        'ip',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function previousPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'previous_package_id');
    }
}
