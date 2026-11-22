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

    /**
     * Publish the listing (auto-approved). Used by submit/publish and when its package payment is confirmed.
     */
    public function publish(): void
    {
        $snapshot = $this->workflow_snapshot ?? [];

        $this->update([
            'status' => 'published',
            'moderation_status' => 'auto_approved',
            'steps_completed' => count($snapshot) > 0 ? count($snapshot) : $this->steps_total,
            'moderated_at' => now(),
            'status_changed_date' => now(),
        ]);

        // Reozom adds the EPA lead pamphlet when a pre-1978 residential listing is finished
        app(\App\Services\DisclosureService::class)->attachLeadPamphlet($this);
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

    /**
     * Everyone who must e-sign this listing's documents, in Reozom's order:
     * signer 1 is the titled owner (by owner type, falling back to the account holder),
     * followed by the additional owners / co-signers.
     *
     * @return array<int, array{name: string, email: string}>
     */
    /**
     * Listing created by an agent. As in the original Reozom, these listings have no
     * statutory listing documents, so nobody signs listing documents for them.
     */
    public function isAgentListing(): bool
    {
        return (bool) $this->user?->isAgent();
    }

    /**
     * Listing-document signers in signing order: the titled owner first, then the additional
     * signers (Reozom sends them out one after another in this order). None for agent listings.
     */
    public function eSignSigners(): array
    {
        return $this->isAgentListing() ? [] : $this->ownerSigners();
    }

    /**
     * The titled owner (owner-type name/email, falling back to the account user) followed by
     * the additional signers, without duplicate emails. Also the seller side of a purchase agreement.
     *
     * @return array<int, array{name: string, email: string}>
     */
    public function ownerSigners(): array
    {
        // The address step holds the owner fields; other steps are only a fallback.
        $values = [];
        $answers = $this->answers()
            ->orderByRaw("CASE WHEN step_id = 'step_property_type_address' THEN 0 ELSE 1 END")
            ->get();
        foreach ($answers as $answer) {
            if (is_array($answer->values)) {
                $values += $answer->values;
            }
        }

        $text = fn (string $key) => trim((string) (is_scalar($values[$key] ?? null) ? $values[$key] : ''));

        [$nameKey, $emailKey] = match ($text('owner_type')) {
            'private' => ['owner_type_name', 'owner_type_email'],
            'trust' => ['owner_type_trust_name', 'owner_type_trust_email'],
            'corporation' => ['owner_type_company_name', 'owner_type_company_email'],
            default => [null, null],
        };

        $primaryEmail = $emailKey ? $text($emailKey) : '';
        if (! filter_var($primaryEmail, FILTER_VALIDATE_EMAIL)) {
            $primaryEmail = (string) $this->user?->email;
        }
        $primaryName = ($nameKey ? $text($nameKey) : '') ?: (string) ($this->user?->name ?: $primaryEmail);

        $signers = [];
        $add = function (string $name, string $email) use (&$signers) {
            $email = strtolower(trim($email));
            if ($email === '' || isset($signers[$email])) {
                return;
            }
            $signers[$email] = ['name' => trim($name) ?: $email, 'email' => $email];
        };

        $add($primaryName, $primaryEmail);

        foreach (self::parseAdditionalSigners($values['additional_signers'] ?? null) as $signer) {
            $add($signer['name'], $signer['email']);
        }

        return array_values($signers);
    }

    /**
     * Additional signers are entered as one "Name email" per line (or a list of {name, email}).
     * Lines without a valid email are skipped because EunoDoc can't send them a signing link.
     *
     * @return array<int, array{name: string, email: string}>
     */
    public static function parseAdditionalSigners(mixed $raw): array
    {
        $entries = [];

        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (is_array($item)) {
                    $entries[] = trim(($item['name'] ?? '').' '.($item['email'] ?? ''));
                } elseif (is_string($item)) {
                    $entries[] = $item;
                }
            }
        } elseif (is_string($raw)) {
            $entries = preg_split('/[\r\n;]+/', $raw) ?: [];
        }

        $signers = [];
        foreach ($entries as $entry) {
            if (! preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $entry, $match)) {
                continue;
            }
            $email = $match[0];
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $name = trim(str_replace($email, '', $entry), " \t,;:|-<>()[]");
            $signers[] = ['name' => $name, 'email' => $email];
        }

        return $signers;
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

    /** Answer bucket for values that have no field in the listing steps (e.g. an edited listing title). */
    public const PUBLIC_OVERRIDES_STEP_ID = 'public_overrides';

    /**
     * Public card values come from the regular listing steps, like the original Reozom.
     * The first source field with a value wins.
     */
    public const PUBLIC_FIELD_SOURCES = [
        'listing_price' => ['list_price', 'listing_price'],
        'bedrooms' => ['total_bedrooms', 'bedrooms'],
        'bathrooms' => ['total_bathrooms', 'bathrooms'],
        'square_feet' => ['ag_square_footage', 'square_feet'],
        'acreage' => ['acreage'],
        'year_built' => ['year_built'],
        'remarks' => ['remarks'],
        'directions' => ['directions'],
    ];

    public const PROPERTY_TYPE_LABELS = [
        'single_family' => 'Single Family Home',
        'condo' => 'Condominium',
        'townhouse' => 'Townhouse',
        'multi_family' => 'Multi-Family',
        'vacant_land' => 'Vacant Land',
    ];

    /**
     * All answers as one flat field => value map (the listing wizard saves fields by name).
     */
    public function answerValues(): array
    {
        $values = [];
        foreach ($this->answers as $answer) {
            if (is_array($answer->values)) {
                foreach ($answer->values as $field => $value) {
                    if (! array_key_exists($field, $values) || ! self::hasValue($values[$field])) {
                        $values[$field] = $value;
                    }
                }
            }
        }

        // The owner step's value wins when the same field was saved in several steps
        foreach ($this->answers as $answer) {
            foreach ((array) $answer->values as $field => $value) {
                if (self::hasValue($value) && $this->stepIdForField($field) === $answer->step_id) {
                    $values[$field] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * Listings created from a process with disclosure steps (Phase 2 flow) instead of the old Disclosures page.
     */
    public function usesDisclosureSteps(): bool
    {
        return ListingProcess::disclosureCodesFromConfig($this->workflow_snapshot ?? []) !== [];
    }

    /**
     * Steps that apply to this listing (showIf matches, skipIf does not), e.g. the Condo
     * disclosure only for condos, no disclosures for trust-owned non-owner-occupied properties.
     */
    public function activeSteps(?array $values = null): array
    {
        $values ??= $this->answerValues();

        return array_values(array_filter(
            $this->workflow_snapshot ?? [],
            fn ($step) => is_array($step) && ListingProcess::stepIsActive($step, $values)
        ));
    }

    /**
     * The step (in this listing's workflow snapshot) that holds a field.
     */
    public function stepIdForField(string $field): ?string
    {
        foreach ($this->workflow_snapshot ?? [] as $step) {
            foreach ($step['sections'] ?? [] as $section) {
                foreach ($section['fields'] ?? [] as $f) {
                    if (($f['name'] ?? null) === $field || ($f['id'] ?? null) === $field) {
                        return $step['id'] ?? null;
                    }
                }
            }
        }

        return null;
    }

    /**
     * A field's answer: from the step that owns the field, otherwise from any saved step.
     */
    public function answerValue(string $field): mixed
    {
        $answers = $this->answers;

        $ownerStep = $this->stepIdForField($field);
        if ($ownerStep) {
            $values = $answers->firstWhere('step_id', $ownerStep)?->values ?? [];
            if (self::hasValue($values[$field] ?? null)) {
                return $values[$field];
            }
        }

        foreach ($answers as $answer) {
            $value = $answer->values[$field] ?? null;
            if (is_array($answer->values) && self::hasValue($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Save a value into the step that owns the field (used by the quick-edit actions).
     * Fields that no step owns go into the public overrides bucket.
     */
    public function setAnswerValue(string $field, mixed $value): void
    {
        $stepId = $this->stepIdForField($field) ?? self::PUBLIC_OVERRIDES_STEP_ID;

        $answer = ListingAnswer::firstOrNew(['listing_id' => $this->id, 'step_id' => $stepId]);
        $values = is_array($answer->values) ? $answer->values : [];
        $values[$field] = $value;
        $answer->values = $values;
        $answer->save();

        $this->unsetRelation('answers');
    }

    /**
     * One public value: edited override, then the old "Public Listing Information" step
     * (listings created before it was removed), then the regular listing steps.
     */
    public function publicValue(string $key): mixed
    {
        foreach ([self::PUBLIC_OVERRIDES_STEP_ID, ListingProcess::LEGACY_PUBLIC_STEP_ID] as $stepId) {
            $values = $this->answers->firstWhere('step_id', $stepId)?->values ?? [];
            if (self::hasValue($values[$key] ?? null)) {
                return $values[$key];
            }
        }

        foreach (self::PUBLIC_FIELD_SOURCES[$key] ?? [$key] as $field) {
            $value = $this->answerValue($field);
            if (self::hasValue($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Quick edit of a public value (price, remarks, title...): updates the step field that holds it.
     * Listings created with the old "Public Listing Information" step get that answer updated too,
     * because it is read first for them.
     */
    public function setPublicValue(string $key, mixed $value): void
    {
        $legacy = ListingAnswer::where('listing_id', $this->id)
            ->where('step_id', ListingProcess::LEGACY_PUBLIC_STEP_ID)->first();
        if ($legacy && is_array($legacy->values) && array_key_exists($key, $legacy->values)) {
            $values = $legacy->values;
            $values[$key] = $value;
            $legacy->values = $values;
            $legacy->save();
        }

        $target = null;
        foreach (self::PUBLIC_FIELD_SOURCES[$key] ?? [] as $field) {
            if ($this->stepIdForField($field) !== null) {
                $target = $field;
                break;
            }
        }

        // No step holds this value (e.g. the title): keep it as an override
        $this->setAnswerValue($target ?? $key, $value);
    }

    /**
     * single_family, condo, vacant_land, ... derived from the listing steps.
     */
    public function publicPropertyType(): ?string
    {
        $legacy = $this->answers->firstWhere('step_id', ListingProcess::LEGACY_PUBLIC_STEP_ID)?->values['property_type'] ?? null;
        if (self::hasValue($legacy)) {
            return (string) $legacy;
        }

        if ($this->stepIdForField('property_sub_type') === null && $this->stepIdForField('acreage') !== null
            && $this->stepIdForField('total_bedrooms') === null) {
            return 'vacant_land';
        }

        return match ((string) $this->answerValue('property_sub_type')) {
            '1' => 'single_family',
            '2' => 'condo',
            default => null,
        };
    }

    /**
     * The original Reozom shows the address as the listing title; e.g. "Single Family Home – 742 Evergreen Terrace, Ann Arbor".
     */
    public function publicTitle(): ?string
    {
        $title = $this->publicValue('listing_title');
        if (self::hasValue($title)) {
            return (string) $title;
        }

        $address = trim(implode(', ', array_filter([$this->address, $this->city])));
        $type = self::PROPERTY_TYPE_LABELS[$this->publicPropertyType() ?? ''] ?? null;

        $generated = trim(implode(' – ', array_filter([$type, $address])));

        return $generated !== '' ? $generated : null;
    }

    public function getPublicListingData(): array
    {
        $propertyType = $this->publicPropertyType();

        $fields = [
            ['id' => 'listing_title', 'label' => 'Title', 'type' => 'text', 'value' => $this->publicTitle()],
            ['id' => 'listing_price', 'label' => 'Price', 'type' => 'number', 'value' => $this->publicValue('listing_price')],
            ['id' => 'property_type', 'label' => 'Property Type', 'type' => 'text', 'value' => self::PROPERTY_TYPE_LABELS[$propertyType ?? ''] ?? $propertyType],
        ];

        $details = $propertyType === 'vacant_land'
            ? ['acreage' => ['Acreage', 'number']]
            : ['bedrooms' => ['Bedrooms', 'number'], 'bathrooms' => ['Bathrooms', 'number'], 'square_feet' => ['Square Feet', 'number'], 'year_built' => ['Year Built', 'text']];

        foreach ($details as $key => [$label, $type]) {
            $value = $this->publicValue($key);
            if (self::hasValue($value)) {
                $fields[] = ['id' => $key, 'label' => $label, 'type' => $type, 'value' => $value];
            }
        }

        return [
            'step_id' => null,
            'fields' => array_map(fn ($f) => $f + ['public_display' => true, 'show_on_card' => true, 'show_on_detail' => true], $fields),
        ];
    }

    private static function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }
}
