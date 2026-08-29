<?php

namespace App\Models;

use Database\Factories\MlsInfoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MlsInfo extends Model
{
    /** @use HasFactory<MlsInfoFactory> */
    use HasFactory;

    protected $fillable = [
        'mls_directory_id',
        'title',
        'countries',
        'public_websites_title',
        'websites',
        'info',
    ];

    protected function casts(): array
    {
        return [
            'countries' => 'array',
            'websites' => 'array',
        ];
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(MlsDirectory::class, 'mls_directory_id');
    }
}
