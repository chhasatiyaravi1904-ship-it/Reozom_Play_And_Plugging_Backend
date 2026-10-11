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
        'service_package_id',
        'listing_process_id',
        'workflow_snapshot',
        'steps_completed',
        'steps_total',
    ];

    protected function casts(): array
    {
        return [
            'steps_completed' => 'integer',
            'steps_total' => 'integer',
            'workflow_snapshot' => 'array',
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

    public function listingProcess(): BelongsTo
    {
        return $this->belongsTo(ListingProcess::class);
    }

    public function answers()
    {
        return $this->hasMany(ListingAnswer::class);
    }

    public function images()
    {
        return $this->hasMany(ListingImage::class)
            ->orderBy('is_primary', 'desc')
            ->orderBy('sort_order');
    }

    public function documents()
    {
        return $this->hasMany(ListingDocument::class);
    }

    public function getPublicListingData(): array
    {
        $systemStepId = 'system_public_info';
        
        $answer = $this->answers->where('step_id', $systemStepId)->first();
        $values = $answer ? ($answer->values ?? []) : [];
        
        $snapshot = $this->workflow_snapshot ?? [];
        $publicStep = null;
        
        foreach ($snapshot as $step) {
            if (($step['id'] ?? '') === $systemStepId) {
                $publicStep = $step;
                break;
            }
        }
        
        if (!$publicStep) {
            return [
                'step_id' => $systemStepId,
                'fields' => []
            ];
        }
        
        $fieldsData = [];
        
        foreach ($publicStep['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $fieldId = $field['id'] ?? null;
                if (!$fieldId) {
                    continue;
                }
                
                $fieldsData[] = [
                    'id' => $fieldId,
                    'label' => $field['label'] ?? $field['name'] ?? '',
                    'type' => $field['type'] ?? 'text',
                    'value' => array_key_exists($fieldId, $values) ? $values[$fieldId] : null,
                    'public_display' => $field['public_display'] ?? true,
                    'show_on_card' => $field['show_on_card'] ?? true,
                    'show_on_detail' => $field['show_on_detail'] ?? true,
                ];
            }
        }
        
        return [
            'step_id' => $systemStepId,
            'fields' => $fieldsData,
        ];
    }
}
