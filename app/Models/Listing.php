<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Listing extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'reference_code',
        'status',
        'address',
        'city',
        'state',
        'zip',
        'steps_completed',
        'steps_total',
    ];

    protected function casts(): array
    {
        return [
            'steps_completed' => 'integer',
            'steps_total' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Listing $listing) {
            $listing->reference_code ??= 'REO-'.strtoupper(Str::random(6));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function progressPercent(): int
    {
        if (! $this->steps_total) {
            return 0;
        }

        return (int) round(($this->steps_completed / $this->steps_total) * 100);
    }
}
