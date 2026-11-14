<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Listing extends Model
{
    use HasFactory;

    // Reozom Numeric Status Constants
    const STATUS_NOT_APPROVED = -1;
    const STATUS_ACTIVE_WAITING_FOR_APPROVAL = 0;
    const STATUS_ACTIVE = 1;
    const STATUS_PENDING = 2;
    const STATUS_CCS = 3;
    const STATUS_SOLD = 4;
    const STATUS_CANCELED = 5;
    const STATUS_SUSPENDED = 6;
    const STATUS_EXPIRED = 7;

    // Reozom String Status Identifiers
    const STATUS_DRAFT = 'draft';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_PUBLISHED = 'published';
    const STATUS_UNDER_CONTRACT = 'under_contract';
    const STATUS_CONTINGENT_CCS = 'ccs';
    const STATUS_STATUS_SOLD = 'sold';
    const STATUS_STATUS_CANCELED = 'canceled';
    const STATUS_STATUS_SUSPENDED = 'suspended';
    const STATUS_STATUS_EXPIRED = 'expired';
    const STATUS_WITHDRAWN = 'withdrawn';
    const STATUS_UNPUBLISHED = 'unpublished';

    protected $fillable = [
        'user_id',
        'reference_code',
        'status',
        'status_changed_date',
        'address',
        'city',
        'state',
        'zip',
        'service_package_id',
        'listing_process_id',
        'workflow_snapshot',
        'steps_completed',
        'steps_total',
        'moderation_status',
        'moderated_by',
        'moderated_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'steps_completed' => 'integer',
            'steps_total' => 'integer',
            'workflow_snapshot' => 'array',
            'moderated_at' => 'datetime',
            'status_changed_date' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Listing $listing) {
            $listing->reference_code ??= 'REO-'.strtoupper(Str::random(6));
        });
    }

    public function getStateCode(): string
    {
        $st = strtoupper(trim((string)$this->state));
        if ($st === '23' || $st === 'MICHIGAN') {
            return 'MI';
        }
        if ($st === '10' || $st === 'FLORIDA') {
            return 'FL';
        }
        if (strlen($st) === 2 && ctype_alpha($st)) {
            return $st;
        }

        // Try to infer from answers
        $addressAns = $this->answers()->where('step_id', 'step_property_type_address')->first();
        if ($addressAns && is_array($addressAns->values)) {
            $sid = (string)($addressAns->values['state_id'] ?? $addressAns->values['state'] ?? '');
            if ($sid === '23' || strtoupper($sid) === 'MI') {
                return 'MI';
            }
            if ($sid === '10' || strtoupper($sid) === 'FL') {
                return 'FL';
            }
        }

        // Try to infer from zip code
        $zip = (string)($this->zip ?? '');
        if (preg_match('/^(48|49)/', $zip)) {
            return 'MI';
        }
        if (preg_match('/^(32|33|34)/', $zip)) {
            return 'FL';
        }

        return $st ?: 'MI';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where(function ($q) {
                $q->whereNull('moderation_status')
                  ->orWhere('moderation_status', '!=', 'rejected');
            });
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->moderation_status !== 'rejected';
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

    public function statusHistories()
    {
        return $this->hasMany(ListingStatusHistory::class)->orderBy('created_at', 'desc');
    }

    public function offers()
    {
        return $this->hasMany(ListingOffer::class)->orderBy('created_at', 'desc');
    }

    public function soldDetails(): HasOne
    {
        return $this->hasOne(ListingSoldDetails::class);
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
