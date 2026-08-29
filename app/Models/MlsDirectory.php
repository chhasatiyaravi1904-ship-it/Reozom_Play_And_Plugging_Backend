<?php

namespace App\Models;

use Database\Factories\MlsDirectoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MlsDirectory extends Model
{
    /** @use HasFactory<MlsDirectoryFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
    ];

    public function infos(): HasMany
    {
        return $this->hasMany(MlsInfo::class);
    }
}
