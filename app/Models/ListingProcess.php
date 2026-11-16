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
        'assigned_zips',
        'config',
        'required_disclosures',
        'documents',
    ];

    protected $casts = [
        'assigned_zips' => 'array',
        'config' => 'array',
        'required_disclosures' => 'array',
        'documents' => 'array',
    ];

    public const DISCLOSURE_STEP_TYPE = 'disclosure';

    public const MAP_STEP_ID = 'step_property_location';

    public const MEDIA_STEP_ID = 'step_images_virtual_tours';

    public const PAYMENT_STEP_ID = 'step_payment';

    /** The removed "Public Listing Information" step; the original Reozom wizard has no such step. */
    public const LEGACY_PUBLIC_STEP_ID = 'system_public_info';

    /** Map follows this step, as in the original Reozom wizard. */
    private const ADDRESS_STEP_ID = 'step_property_type_address';

    /**
     * Steps managed by the system: nobody can edit, delete or move them.
     * Same as the original Reozom wizard (Location on the Map, Pictures & Virtual Tours, Payment).
     */
    public static function lockedSteps(): array
    {
        return [
            self::MAP_STEP_ID => [
                'id' => self::MAP_STEP_ID,
                'name' => 'Location on the Map',
                'description' => 'Pin the exact property location on the map.',
                'type' => 'locked',
                'kind' => 'map',
                'locked' => true,
                'visible' => true,
                'sections' => [[
                    'id' => 'section_map_coordinates',
                    'order' => 1,
                    'name' => 'Map Coordinates',
                    'description' => 'Latitude and longitude coordinates.',
                    'fields' => [
                        ['id' => 'latitude', 'name' => 'latitude', 'label' => 'Latitude', 'type' => 'text', 'required' => true, 'active' => true, 'showInExport' => true, 'helpText' => 'Decimal GPS latitude, e.g. 42.2808'],
                        ['id' => 'longitude', 'name' => 'longitude', 'label' => 'Longitude', 'type' => 'text', 'required' => true, 'active' => true, 'showInExport' => true, 'helpText' => 'Decimal GPS longitude, e.g. -83.7430'],
                    ],
                ]],
            ],
            self::MEDIA_STEP_ID => [
                'id' => self::MEDIA_STEP_ID,
                'name' => 'Pictures & Virtual Tours',
                'description' => 'Upload property photos and add virtual tour links.',
                'type' => 'locked',
                'kind' => 'media',
                'locked' => true,
                'visible' => true,
                'sections' => [[
                    'id' => 'section_media_uploads',
                    'order' => 1,
                    'name' => 'Media & Agreements',
                    'description' => 'Property photography and unbranded tour URLs.',
                    'fields' => [
                        ['id' => 'property_images', 'name' => 'property_images', 'label' => 'Upload Property Images', 'type' => 'file', 'required' => true, 'active' => true, 'showInExport' => false, 'helpText' => 'Upload high resolution exterior and interior photos (JPG, PNG)'],
                        ['id' => 'virtual_tour_url', 'name' => 'virtual_tour_url', 'label' => 'Virtual Tour Link', 'type' => 'text', 'required' => false, 'active' => true, 'showInExport' => true, 'helpText' => 'Unbranded 3D virtual tour or walkthrough URL'],
                        ['id' => 'media_terms_agreed', 'name' => 'media_terms_agreed', 'label' => 'I own or hold license to all uploaded photos and media', 'type' => 'checkbox', 'required' => true, 'active' => true, 'defaultValue' => '1', 'showInExport' => true],
                    ],
                ]],
            ],
            self::PAYMENT_STEP_ID => [
                'id' => self::PAYMENT_STEP_ID,
                'name' => 'Payment',
                'description' => 'Review your service package before publishing.',
                'type' => 'locked',
                'kind' => 'payment',
                'locked' => true,
                'visible' => true,
                'sections' => [],
            ],
        ];
    }

    public static function isLockedStepId(string $id): bool
    {
        return array_key_exists($id, self::lockedSteps());
    }

    /**
     * Put a config in the Reozom shape: drop the old public-info step and restore the locked steps
     * from their definitions (client edits to their content are ignored, and they can't be deleted).
     * Map and Pictures can be moved anywhere; Payment is always the last step.
     * Missing steps are put back where the original Reozom has them: Map right after
     * "Property Type & Address", Pictures just before Payment.
     */
    public static function enforceLockedSteps(array $config): array
    {
        $locked = self::lockedSteps();

        $result = [];
        $seen = [];
        foreach ($config as $step) {
            $id = is_array($step) ? (string) ($step['id'] ?? '') : '';

            if ($id === '' || $id === self::LEGACY_PUBLIC_STEP_ID || ($step['type'] ?? null) === 'public_listing'
                || $id === self::PAYMENT_STEP_ID || isset($seen[$id])) {
                continue;
            }

            if (self::isLockedStepId($id)) {
                $seen[$id] = true;
                $result[] = $locked[$id];

                continue;
            }

            $result[] = $step;
        }

        if (! isset($seen[self::MAP_STEP_ID])) {
            $mapAt = 0;
            foreach ($result as $index => $step) {
                if ($step['id'] === self::ADDRESS_STEP_ID) {
                    $mapAt = $index + 1;
                    break;
                }
            }
            array_splice($result, $mapAt, 0, [$locked[self::MAP_STEP_ID]]);
        }

        if (! isset($seen[self::MEDIA_STEP_ID])) {
            $result[] = $locked[self::MEDIA_STEP_ID];
        }

        $result[] = $locked[self::PAYMENT_STEP_ID];

        foreach ($result as $index => &$step) {
            $step['order'] = $index + 1;
            unset($step['isSystem']);
        }

        return $result;
    }

    /**
     * Original Reozom skips the disclosure step for trust-owned, non-owner-occupied properties.
     */
    public const TRUST_NON_OWNER_SKIP_RULE = [
        'all' => [
            ['field' => 'owner_type', 'equals' => 'trust'],
            ['field' => 'owner_type_trust_type', 'equals' => 'non_owner_occupied'],
        ],
    ];

    /**
     * Evaluate a condition against flat listing answers. Used for step showIf/skipIf and document rules.
     * Supported: {field, equals} {field, in: []} {field, lt: n} {field, empty: true}
     *            {all: [...]} {any: [...]} {not: rule}. A null/empty rule matches.
     */
    public static function conditionMatches(?array $rule, array $values): bool
    {
        if (empty($rule)) {
            return true;
        }

        if (isset($rule['all'])) {
            foreach ((array) $rule['all'] as $sub) {
                if (! self::conditionMatches($sub, $values)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($rule['any'])) {
            foreach ((array) $rule['any'] as $sub) {
                if (self::conditionMatches($sub, $values)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($rule['not'])) {
            return ! self::conditionMatches($rule['not'], $values);
        }

        $value = $values[$rule['field'] ?? ''] ?? null;
        $isEmpty = $value === null || $value === '' || $value === [];

        if (array_key_exists('empty', $rule)) {
            return $isEmpty === (bool) $rule['empty'];
        }
        if (array_key_exists('equals', $rule)) {
            return ! $isEmpty && (string) $value === (string) $rule['equals'];
        }
        if (array_key_exists('in', $rule)) {
            return ! $isEmpty && in_array((string) $value, array_map('strval', (array) $rule['in']), true);
        }
        if (array_key_exists('lt', $rule)) {
            return ! $isEmpty && is_numeric($value) && (float) $value < (float) $rule['lt'];
        }

        return true;
    }

    /**
     * Whether a step applies to a listing: its showIf matches and its skipIf does not.
     */
    public static function stepIsActive(array $step, array $values): bool
    {
        if (! empty($step['showIf']) && ! self::conditionMatches($step['showIf'], $values)) {
            return false;
        }

        return empty($step['skipIf']) || ! self::conditionMatches($step['skipIf'], $values);
    }

    public function getRequiredDisclosures(): array
    {
        return $this->required_disclosures ?? ($this->config['required_disclosures'] ?? []);
    }

    public function isSystemDefault(): bool
    {
        return $this->agent_id === null && $this->type === 'default';
    }

    /**
     * Copy this process (steps, disclosure steps and questions) into a new custom process.
     */
    public function copyFor(?int $agentId, string $name): self
    {
        return self::create([
            'name' => $name,
            'type' => 'custom',
            'status' => 'active',
            'agent_id' => $agentId,
            'assigned_zips' => $this->assigned_zips ?? [],
            'required_disclosures' => $this->getRequiredDisclosures(),
            'documents' => $this->documents,
            'config' => $this->config ?? [],
        ]);
    }

    /**
     * Why the agent may not create another process (no active package or package limit reached), or null.
     *
     * @return array{0: int, 1: string}|null [HTTP status, message]
     */
    public static function agentCreationBlocker(User $agent): ?array
    {
        if (! $agent->hasActivePackage()) {
            return [403, 'Active package required to create listing processes.'];
        }

        $activePackage = $agent->currentAgentPackage?->package;
        if ($activePackage && $activePackage->max_listing_processes !== null
            && $agent->listingProcesses()->count() >= $activePackage->max_listing_processes) {
            return [422, 'Package limit reached.'];
        }

        return null;
    }

    public function hasDisclosureSteps(): bool
    {
        return self::disclosureCodesFromConfig($this->config ?? []) !== [];
    }

    public static function disclosureStepId(string $code): string
    {
        return 'disclosure_'.$code;
    }

    public static function isDisclosureStep(array $step): bool
    {
        return ($step['type'] ?? null) === self::DISCLOSURE_STEP_TYPE && ! empty($step['disclosure_code']);
    }

    /**
     * @return string[] disclosure codes in the order their steps appear in the config
     */
    public static function disclosureCodesFromConfig(array $config): array
    {
        $codes = [];
        foreach ($config as $step) {
            if (is_array($step) && self::isDisclosureStep($step)) {
                $codes[] = (string) $step['disclosure_code'];
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Build a process step from a master disclosure. The questions are copied so the
     * process owner can customize them without changing the master disclosure.
     */
    public static function buildDisclosureStep(PropertyDisclosure $disclosure): array
    {
        $schema = $disclosure->schema ?? [];

        $step = [
            'id' => self::disclosureStepId($disclosure->code),
            'type' => self::DISCLOSURE_STEP_TYPE,
            'disclosure_code' => $disclosure->code,
            'name' => $disclosure->title,
            'description' => (string) ($disclosure->description ?? ''),
            'visible' => true,
            'customized' => false,
            'skipIf' => self::TRUST_NON_OWNER_SKIP_RULE,
            'sections' => self::normalizeSections($schema['sections'] ?? []),
        ];

        if (! empty($schema['step_show_if']) && is_array($schema['step_show_if'])) {
            $step['showIf'] = $schema['step_show_if'];
        }

        return $step;
    }

    /**
     * Add steps for newly selected disclosures, drop steps for unselected ones and keep
     * existing (possibly customized) disclosure steps untouched.
     */
    public static function syncDisclosureSteps(array $config, array $codes): array
    {
        $codes = array_values(array_unique(array_filter($codes, fn ($c) => is_string($c) && $c !== '')));

        $seen = [];
        $synced = [];
        foreach ($config as $step) {
            if (! is_array($step)) {
                continue;
            }

            if (self::isDisclosureStep($step)) {
                $code = (string) $step['disclosure_code'];
                if (! in_array($code, $codes, true) || isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                $step['id'] = self::disclosureStepId($code);
                $step['type'] = self::DISCLOSURE_STEP_TYPE;
            }

            $synced[] = $step;
        }

        $missing = array_values(array_diff($codes, array_keys($seen)));
        if ($missing !== []) {
            $masters = PropertyDisclosure::whereIn('code', $missing)->get()->keyBy('code');
            $insertAt = self::disclosureInsertIndex($synced);

            foreach ($missing as $code) {
                if (! $masters->has($code)) {
                    continue;
                }
                array_splice($synced, $insertAt, 0, [self::buildDisclosureStep($masters[$code])]);
                $insertAt++;
            }
        }

        foreach ($synced as $index => &$step) {
            $step['order'] = $index + 1;
        }

        return $synced;
    }

    /**
     * Disclosures go right before the pictures step, as in the original Reozom wizard.
     */
    private static function disclosureInsertIndex(array $config): int
    {
        foreach ($config as $index => $step) {
            if (str_contains((string) ($step['id'] ?? ''), 'images')) {
                return $index;
            }
        }

        return count($config);
    }

    private static function normalizeSections(array $sections): array
    {
        $normalized = [];
        foreach (array_values($sections) as $sIndex => $section) {
            $fields = [];
            foreach (array_values($section['fields'] ?? []) as $field) {
                $id = (string) ($field['id'] ?? $field['name'] ?? 'field_'.uniqid());
                $fields[] = array_merge($field, [
                    'id' => $id,
                    'name' => (string) ($field['name'] ?? $id),
                    'label' => (string) ($field['label'] ?? $id),
                    'type' => (string) ($field['type'] ?? 'text'),
                    'active' => $field['active'] ?? true,
                ]);
            }

            $normalized[] = array_merge($section, [
                'id' => (string) ($section['id'] ?? 'section_'.($sIndex + 1)),
                'order' => $sIndex + 1,
                'name' => (string) ($section['name'] ?? 'Section '.($sIndex + 1)),
                'fields' => $fields,
            ]);
        }

        return $normalized;
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Service packages whose sellers use this process (one process can serve many packages).
     */
    public function servicePackages()
    {
        return $this->hasMany(ServicePackage::class);
    }

    /**
     * IDs of the packages linked to this process, exposed to the API as `service_package_ids`.
     */
    public function servicePackageIds(): array
    {
        $packages = $this->relationLoaded('servicePackages') ? $this->servicePackages : $this->servicePackages()->get(['id']);

        return $packages->pluck('id')->sort()->values()->all();
    }
}
