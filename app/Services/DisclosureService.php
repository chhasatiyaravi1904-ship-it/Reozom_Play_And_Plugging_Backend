<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use App\Models\Listing;
use App\Models\ListingAnswer;
use App\Models\ListingDocument;
use App\Models\ListingProcess;
use Illuminate\Support\Str;

class DisclosureService
{
    /**
     * Get all available active disclosures for a listing, with selection and statutory status.
     */
    public function getAvailableDisclosuresForListing(Listing $listing): array
    {
        $all = \App\Models\PropertyDisclosure::active()
            ->forListing($listing)
            ->orderBy('order')
            ->get();

        $selectedCodes = $this->getSelectedDisclosureCodes($listing);

        $result = [];
        foreach ($all as $d) {
            $schema = $d->schema ?? [];
            $sections = $schema['sections'] ?? [];
            $questionCount = 0;
            foreach ($sections as $s) {
                $questionCount += count($s['fields'] ?? []);
            }

            $processRequired = in_array($d->code, $listing->listingProcess?->getRequiredDisclosures() ?? []);
            $isMandatory = $processRequired || ($d->is_required && in_array($d->category, ['statutory']));

            $result[] = [
                'code' => $d->code,
                'title' => $d->title,
                'description' => $d->description,
                'category' => $d->category,
                'state_code' => $d->state_code,
                'property_type' => $d->property_type,
                'order' => $d->order,
                'is_required' => $d->is_required,
                'is_mandatory' => $isMandatory,
                'is_selected' => in_array($d->code, $selectedCodes),
                'sections_count' => count($sections),
                'questions_count' => $questionCount,
                'sections' => $sections,
            ];
        }

        return $result;
    }

    /**
     * Determine which disclosure codes are currently selected/required for this listing.
     * Hierarchy:
     * 1. Saved selection in listing answers ('required_disclosures')
     * 2. ListingProcess configured required_disclosures
     * 3. State/property-type statutory defaults (PropertyDisclosure where is_required = true)
     */
    public function getSelectedDisclosureCodes(Listing $listing): array
    {
        // 1. Explicit selection saved for listing
        $saved = $listing->answers()->where('step_id', 'required_disclosures')->first();
        if ($saved && is_array($saved->values) && isset($saved->values['selected_codes'])) {
            return (array) $saved->values['selected_codes'];
        }

        // 2. ListingProcess configured required disclosures
        $processDisclosures = $listing->listingProcess?->getRequiredDisclosures();
        if (!empty($processDisclosures) && is_array($processDisclosures)) {
            return $processDisclosures;
        }

        // 3. Defaults based on statutory requirements for this listing
        return \App\Models\PropertyDisclosure::active()
            ->forListing($listing)
            ->where(function ($q) {
                $q->where('is_required', true)
                  ->orWhere('category', 'statutory');
            })
            ->pluck('code')
            ->all();
    }

    /**
     * Get statutory disclosure schema matching the Reozom platform specifications.
     * Returns schemas for only the selected disclosures (or all applicable if none specified).
     */
    public function getDisclosureSchema(Listing $listing, ?array $selectedCodes = null): array
    {
        $selectedCodes = $selectedCodes ?? $this->getSelectedDisclosureCodes($listing);

        $query = \App\Models\PropertyDisclosure::active()
            ->forListing($listing)
            ->orderBy('order');

        if (!empty($selectedCodes)) {
            $query->whereIn('code', $selectedCodes);
        }

        $dynamicDisclosures = $query->get();

        if ($dynamicDisclosures->isNotEmpty()) {
            $steps = [];
            foreach ($dynamicDisclosures as $idx => $d) {
                $schema = $d->schema ?? [];
                $steps[] = [
                    'id' => $d->code,
                    'name' => $d->title,
                    'order' => $d->order ?? ($idx + 1),
                    'description' => $d->description,
                    'is_required' => $d->is_required,
                    'category' => $d->category,
                    'state_code' => $d->state_code,
                    'property_type' => $d->property_type,
                    'sections' => $schema['sections'] ?? [],
                ];
            }
            return $steps;
        }

        return $this->getDefaultDisclosureSchema();
    }

    /**
     * Fallback standard statutory property disclosures.
     */
    public function getDefaultDisclosureSchema(): array
    {
        return [
            [
                'id' => 'property_conditions',
                'name' => 'Property Conditions & Structural Improvements',
                'order' => 1,
                'sections' => [
                    [
                        'id' => 'water_roof_insulation',
                        'name' => 'Basement, Roof, & Insulation',
                        'order' => 1,
                        'fields' => [
                            [
                                'id' => 'basement_water_id',
                                'name' => 'basement_water_id',
                                'label' => 'Basement / Crawlspace: Has there been evidence of water?',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'No', 'value' => 'no'],
                                    ['label' => 'Yes', 'value' => 'yes'],
                                    ['label' => 'Unknown', 'value' => 'unknown'],
                                ],
                            ],
                            [
                                'id' => 'basement_water_explain',
                                'name' => 'basement_water_explain',
                                'label' => 'If yes, please explain basement/crawlspace water evidence:',
                                'type' => 'textarea',
                                'required' => false,
                                'placeholder' => 'Explain any seepage, previous sump pump failure, or mitigation performed...',
                            ],
                            [
                                'id' => 'roof_leaks_id',
                                'name' => 'roof_leaks_id',
                                'label' => 'Roof: Are you aware of any current or past roof leaks?',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'No', 'value' => 'no'],
                                    ['label' => 'Yes', 'value' => 'yes'],
                                    ['label' => 'Unknown', 'value' => 'unknown'],
                                ],
                            ],
                            [
                                'id' => 'roof_age',
                                'name' => 'roof_age',
                                'label' => 'Approximate Roof Age (enter years or N/A if unknown):',
                                'type' => 'text',
                                'required' => true,
                                'placeholder' => 'e.g., 6 years, 2018, or N/A',
                            ],
                            [
                                'id' => 'insulation',
                                'name' => 'insulation',
                                'label' => 'Describe Insulation (type/coverage, or enter N/A if unknown):',
                                'type' => 'text',
                                'required' => true,
                                'placeholder' => 'e.g., Fiberglass blown-in, R-38, Foam, N/A',
                            ],
                            [
                                'id' => 'installed_uffi_id',
                                'name' => 'installed_uffi_id',
                                'label' => 'Urea Formaldehyde Foam Insulation (UFFI) installed?',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'No', 'value' => 'no'],
                                    ['label' => 'Yes', 'value' => 'yes'],
                                    ['label' => 'Unknown', 'value' => 'unknown'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => 'mechanical_systems',
                        'name' => 'Mechanical, Plumbing, & Environmental',
                        'order' => 2,
                        'fields' => [
                            [
                                'id' => 'plumbing_system',
                                'name' => 'plumbing_system',
                                'label' => 'Plumbing: Any known defects or problems?',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'No', 'value' => 'no'],
                                    ['label' => 'Yes', 'value' => 'yes'],
                                    ['label' => 'Unknown', 'value' => 'unknown'],
                                ],
                            ],
                            [
                                'id' => 'electrical_system',
                                'name' => 'electrical_system',
                                'label' => 'Electrical System: Any known problems or aluminum wiring?',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'No', 'value' => 'no'],
                                    ['label' => 'Yes', 'value' => 'yes'],
                                    ['label' => 'Unknown', 'value' => 'unknown'],
                                ],
                            ],
                            [
                                'id' => 'heating_system',
                                'name' => 'heating_system',
                                'label' => 'Heating / Central Air: Type and known defects?',
                                'type' => 'text',
                                'required' => true,
                                'placeholder' => 'e.g. Forced Air Gas (2019) - No known defects',
                            ],
                            [
                                'id' => 'well_septic_id',
                                'name' => 'well_septic_id',
                                'label' => 'Well & Septic: Has private well or septic had any known issues?',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'No / Municipal Water & Sewer', 'value' => 'no_municipal'],
                                    ['label' => 'Well/Septic in Good Working Order', 'value' => 'good'],
                                    ['label' => 'Yes, Issues Exist (Explain below)', 'value' => 'yes_issues'],
                                    ['label' => 'Unknown', 'value' => 'unknown'],
                                ],
                            ],
                            [
                                'id' => 'environmental_problems',
                                'name' => 'environmental_problems',
                                'label' => 'Environmental: Awareness of radon, asbestos, or underground fuel tanks?',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'No', 'value' => 'no'],
                                    ['label' => 'Yes', 'value' => 'yes'],
                                    ['label' => 'Unknown', 'value' => 'unknown'],
                                ],
                            ],
                            [
                                'id' => 'structural_settling',
                                'name' => 'structural_settling',
                                'label' => 'Structural: Any settling, foundation cracks, or flooding history?',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'No', 'value' => 'no'],
                                    ['label' => 'Yes', 'value' => 'yes'],
                                    ['label' => 'Unknown', 'value' => 'unknown'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'lead_paint_disclosure',
                'name' => 'Lead-Based Paint Hazard Disclosure',
                'order' => 2,
                'sections' => [
                    [
                        'id' => 'lead_paint_info',
                        'name' => 'Federal Lead-Based Paint Compliance',
                        'order' => 1,
                        'fields' => [
                            [
                                'id' => 'built_before_1978',
                                'name' => 'built_before_1978',
                                'label' => 'Was this residential property constructed prior to 1978?',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'No (Built in 1978 or later)', 'value' => 'no'],
                                    ['label' => 'Yes (Built before 1978)', 'value' => 'yes'],
                                    ['label' => 'Unknown', 'value' => 'unknown'],
                                ],
                            ],
                            [
                                'id' => 'lead_knowledge_id',
                                'name' => 'lead_knowledge_id',
                                'label' => 'Presence of known lead-based paint and/or lead hazards:',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'Seller has no knowledge of lead-based paint or hazards', 'value' => 'no_knowledge'],
                                    ['label' => 'Known lead-based paint or hazards are present', 'value' => 'known_present'],
                                ],
                            ],
                            [
                                'id' => 'lead_reports_id',
                                'name' => 'lead_reports_id',
                                'label' => 'Records and reports available to the seller pertaining to lead paint:',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'Seller has no reports or records pertaining to lead paint', 'value' => 'no_reports'],
                                    ['label' => 'Seller has provided purchaser with all available records and reports', 'value' => 'has_reports'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'appliances_fixtures',
                'name' => 'Appliances & Property Fixtures',
                'order' => 3,
                'sections' => [
                    [
                        'id' => 'included_appliances',
                        'name' => 'Included Items and Working Condition',
                        'order' => 1,
                        'fields' => [
                            [
                                'id' => 'range_oven',
                                'name' => 'range_oven',
                                'label' => 'Range / Oven',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'Included & Working', 'value' => 'included_working'],
                                    ['label' => 'Included (Working condition unknown/as-is)', 'value' => 'included_as_is'],
                                    ['label' => 'Not Included / Excluded from Sale', 'value' => 'not_included'],
                                    ['label' => 'Not Present', 'value' => 'not_present'],
                                ],
                            ],
                            [
                                'id' => 'refrigerator',
                                'name' => 'refrigerator',
                                'label' => 'Refrigerator',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'Included & Working', 'value' => 'included_working'],
                                    ['label' => 'Included (Working condition unknown/as-is)', 'value' => 'included_as_is'],
                                    ['label' => 'Not Included / Excluded from Sale', 'value' => 'not_included'],
                                    ['label' => 'Not Present', 'value' => 'not_present'],
                                ],
                            ],
                            [
                                'id' => 'dishwasher',
                                'name' => 'dishwasher',
                                'label' => 'Dishwasher',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'Included & Working', 'value' => 'included_working'],
                                    ['label' => 'Not Included / Excluded from Sale', 'value' => 'not_included'],
                                    ['label' => 'Not Present', 'value' => 'not_present'],
                                ],
                            ],
                            [
                                'id' => 'washer_dryer',
                                'name' => 'washer_dryer',
                                'label' => 'Washer & Dryer',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'Included & Working', 'value' => 'included_working'],
                                    ['label' => 'Not Included / Excluded from Sale', 'value' => 'not_included'],
                                    ['label' => 'Not Present', 'value' => 'not_present'],
                                ],
                            ],
                            [
                                'id' => 'water_heater',
                                'name' => 'water_heater',
                                'label' => 'Water Heater',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'Owned & Working', 'value' => 'owned_working'],
                                    ['label' => 'Leased / Rented', 'value' => 'leased'],
                                    ['label' => 'Not Working', 'value' => 'not_working'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'ownership_additional',
                'name' => 'Ownership History & HOA Disclosures',
                'order' => 4,
                'sections' => [
                    [
                        'id' => 'hoa_legal',
                        'name' => 'HOA and Legal Information',
                        'order' => 1,
                        'fields' => [
                            [
                                'id' => 'date_owned',
                                'name' => 'date_owned',
                                'label' => 'The Seller has owned the property since (Year):',
                                'type' => 'text',
                                'required' => true,
                                'placeholder' => 'e.g. 2017',
                            ],
                            [
                                'id' => 'common_areas_id',
                                'name' => 'common_areas_id',
                                'label' => 'Is the property part of a Homeowners Association (HOA) or Condominium?',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'No', 'value' => 'no'],
                                    ['label' => 'Yes', 'value' => 'yes'],
                                ],
                            ],
                            [
                                'id' => 'litigation_id',
                                'name' => 'litigation_id',
                                'label' => 'Any pending lawsuits, liens, or boundary disputes affecting the property?',
                                'type' => 'select',
                                'required' => true,
                                'options' => [
                                    ['label' => 'No', 'value' => 'no'],
                                    ['label' => 'Yes', 'value' => 'yes'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Fetch existing disclosure answers for a listing.
     */
    public function getAnswers(Listing $listing): array
    {
        $answer = $listing->answers()->where('step_id', 'disclosures')->first();
        if ($answer && is_array($answer->values)) {
            return $answer->values;
        }

        // Check if saved individually by step
        $all = [];
        foreach (['property_conditions', 'lead_paint_disclosure', 'appliances_fixtures', 'ownership_additional'] as $stepId) {
            $stepAns = $listing->answers()->where('step_id', $stepId)->first();
            if ($stepAns && is_array($stepAns->values)) {
                $all[$stepId] = $stepAns->values;
            }
        }

        return $all;
    }

    /**
     * Save disclosures and automatically provision/regenerate statutory listing documents.
     */
    /**
     * Save disclosures and automatically provision/regenerate statutory listing documents.
     */
    public function saveDisclosures(Listing $listing, array $answers, ?array $selectedCodes = null): void
    {
        // 1. If explicit selection was made, persist it
        if ($selectedCodes !== null) {
            $selectedCodes = array_values(array_unique($selectedCodes));
            $listing->answers()->updateOrCreate(
                ['step_id' => 'required_disclosures'],
                ['values' => ['selected_codes' => $selectedCodes]]
            );
        } else {
            $selectedCodes = $this->getSelectedDisclosureCodes($listing);
        }

        // 2. Persist master disclosures answer
        $listing->answers()->updateOrCreate(
            ['step_id' => 'disclosures'],
            ['values' => $answers]
        );

        // Also persist per-step breakdown for modular views
        foreach ($answers as $stepKey => $values) {
            if (is_array($values)) {
                $listing->answers()->updateOrCreate(
                    ['step_id' => $stepKey],
                    ['values' => $values]
                );
            }
        }

        // 3. Automatically provision or regenerate statutory listing documents for selected disclosures
        $this->regenerateStatutoryDocuments($listing, $selectedCodes);
    }

    /**
     * Provision and regenerate the standard statutory documents for this listing matching the selected disclosures.
     * Reozom Document Types:
     * - sellers_disclosure: Seller's Disclosure Statement (MI/FL Residential or Vacant Land)
     * - lead_disclosure: Lead-Based Paint Hazard Disclosure (Pre-1978 Housing)
     * - condo_disclosure: Florida Condominium Seller's Property Disclosure
     * - listing_agreement: Exclusive Right to Sell Listing Agreement
     * - agency_disclosure: Agency Disclosure Statement
     */
    public function regenerateStatutoryDocuments(Listing $listing, ?array $selectedCodes = null): void
    {
        if ($listing->isAgentListing()) {
            $this->removeUnsignedListingDocuments($listing);

            return;
        }

        $selectedCodes = $selectedCodes ?? $this->getSelectedDisclosureCodes($listing);

        $statutoryDefs = [];

        // Sellers Disclosure (MI, FL, or general property condition disclosures)
        $hasSellersDisclosure = empty($selectedCodes)
            || in_array('mi_residential_appliances', $selectedCodes)
            || in_array('mi_residential_conditions', $selectedCodes)
            || in_array('mi_vacant_land_disclosure', $selectedCodes)
            || in_array('fl_residential_single_family', $selectedCodes)
            || in_array('fl_vacant_land_disclosure', $selectedCodes)
            || in_array('property_conditions', $selectedCodes);

        if ($hasSellersDisclosure) {
            $statutoryDefs[] = [
                'type' => 'sellers_disclosure',
                'name' => "Seller's Disclosure Statement",
                'description' => 'Mandatory statutory disclosure statement describing property conditions and improvements.',
            ];
        }

        // Lead-Based Paint Hazard Disclosure
        if (empty($selectedCodes) || in_array('lead_paint_disclosure', $selectedCodes) || in_array('lead_paint', $selectedCodes)) {
            $statutoryDefs[] = [
                'type' => 'lead_disclosure',
                'name' => 'Lead-Based Paint Hazard Disclosure',
                'description' => 'Federal EPA & State statutory lead-based paint hazard disclosure.',
            ];
        }

        // Florida Condo Disclosure
        if (in_array('fl_residential_condo', $selectedCodes)) {
            $statutoryDefs[] = [
                'type' => 'condo_disclosure',
                'name' => 'Condominium Seller\'s Property Disclosure',
                'description' => 'Florida statutory condominium governance, reserve funding, and milestone inspection disclosure.',
            ];
        }

        // Brokerage agreements: only once the service package is paid (Reozom "free listing" has disclosures only)
        if ($this->listingIsPaid($listing)) {
            $statutoryDefs[] = [
                'type' => 'listing_agreement',
                'name' => 'Exclusive Right to Sell Listing Agreement',
                'description' => 'Exclusive listing agreement with brokerage, package terms, and commission specifications.',
            ];
            $statutoryDefs[] = [
                'type' => 'agency_disclosure',
                'name' => 'Agency Disclosure Statement',
                'description' => 'Disclosure Regarding Real Estate Agency Relationships and duties.',
            ];
        }

        foreach ($statutoryDefs as $def) {
            $existing = $listing->documents()->where('type', $def['type'])->first();

            if (!$existing) {
                $listing->documents()->create([
                    'user_id' => $listing->user_id,
                    'type' => $def['type'],
                    'original_name' => "{$def['name']}.pdf",
                    'path' => "listings/{$listing->id}/statutory/{$def['type']}.html",
                    'mime_type' => 'application/pdf',
                    'size' => 1024 * 45, // 45 KB virtual document
                    'status' => 'unsigned',
                    'admin_notes' => 'Generated from seller disclosures questionnaire.',
                ]);
            }
        }

        // Clean up any statutory disclosures that are no longer selected
        $activeStatutoryTypes = array_column($statutoryDefs, 'type');
        $statutoryPool = ['sellers_disclosure', 'lead_disclosure', 'condo_disclosure'];
        $unselectedTypes = array_diff($statutoryPool, $activeStatutoryTypes);
        if (!empty($unselectedTypes)) {
            $listing->documents()->whereIn('type', $unselectedTypes)->delete();
        }
        // Agreements created before this rule on a still-unpaid listing (signed ones are kept)
        $listing->documents()
            ->whereIn('type', array_diff(['listing_agreement', 'agency_disclosure'], $activeStatutoryTypes))
            ->where('status', '!=', 'signed')
            ->delete();
    }

    /**
     * Make sure a listing has its documents: listings with disclosure steps use document templates,
     * older listings keep the previous statutory document set.
     */
    public function provisionDocuments(Listing $listing): void
    {
        if ($listing->usesDisclosureSteps()) {
            $this->generateListingDocuments($listing);
        } else {
            $this->regenerateStatutoryDocuments($listing);
        }
    }

    /**
     * Templates that apply to a listing, like the original Reozom DocumentsService:
     * state + property type set, filtered by the process document list, the template rules
     * (HOA mandatory, year built, trust...) and, for disclosure documents, whether the
     * disclosure step applies to this listing (Single Family vs Condo, skipped for trusts).
     */
    public function applicableTemplates(Listing $listing, ?array $values = null): \Illuminate\Support\Collection
    {
        $listing->loadMissing(['answers', 'listingProcess']);
        $values ??= $listing->answerValues();

        $group = $listing->publicPropertyType() === 'vacant_land' ? 'vacant_land' : 'residential';
        $enabled = $listing->listingProcess?->documents;

        $activeDisclosureCodes = ListingProcess::disclosureCodesFromConfig($listing->activeSteps($values));

        // Reozom "free listing": until the service package is paid only the disclosure documents are
        // created (none at all for MI residential new construction); the full set once it is paid
        $beforePayment = ! $this->listingIsPaid($listing);
        if ($beforePayment && $group === 'residential' && $listing->getStateCode() === 'MI' && $this->isNewConstruction($values)) {
            return collect();
        }

        return DocumentTemplate::active()
            ->for($listing->getStateCode(), $group)
            ->orderBy('order')
            ->get()
            ->filter(fn (DocumentTemplate $t) => $enabled === null || in_array($t->code, (array) $enabled, true))
            ->filter(fn (DocumentTemplate $t) => ListingProcess::conditionMatches($t->rules, $values))
            ->filter(fn (DocumentTemplate $t) => $t->source !== DocumentTemplate::SOURCE_DISCLOSURE
                || array_intersect((array) $t->disclosure_codes, $activeDisclosureCodes) !== [])
            ->filter(fn (DocumentTemplate $t) => ! $beforePayment || $t->available_before_payment)
            ->values();
    }

    /** Free or package-less listings count as paid, as in PaymentService. */
    private function listingIsPaid(Listing $listing): bool
    {
        return app(PaymentService::class)->listingIsPaid($listing);
    }

    private function isNewConstruction(array $values): bool
    {
        return in_array(strtolower((string) ($values['new_construction'] ?? '')), ['yes', '1', 'true'], true);
    }

    /**
     * Reozom attaches the EPA pamphlet "Protect Your Family From Lead In Your Home" to a finished
     * MI/FL residential listing built before 1978 (not for agents), as an uploaded document.
     */
    public const LEAD_PAMPHLET_TYPE = 'lead_pamphlet';

    /** Reozom "Extra Documents": fixed forms shown with the documents (View / Send to email only). */
    public const EXTRA_DOCUMENT_TYPES = ['condo_governance_form'];

    /**
     * Reozom Florida/VacantLand DocumentsService::getExtraDocuments: the "Condo Governance Form".
     * Added once the PDF is placed at resources/documents/fl-condo-governance-form.pdf.
     */
    public function attachExtraDocuments(Listing $listing): void
    {
        if ($listing->isAgentListing() || $listing->getStateCode() !== 'FL' || $listing->publicPropertyType() !== 'vacant_land') {
            return;
        }

        $source = resource_path('documents/fl-condo-governance-form.pdf');
        if (! is_file($source) || $listing->documents()->where('type', 'condo_governance_form')->exists()) {
            return;
        }

        $path = "listings/{$listing->id}/documents/fl-condo-governance-form.pdf";
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, file_get_contents($source));

        $listing->documents()->create([
            'user_id' => $listing->user_id,
            'type' => 'condo_governance_form',
            'original_name' => 'Condo Governance Form.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => filesize($source),
            'status' => 'uploaded',
            'admin_notes' => 'Extra document for Florida vacant land listings.',
        ]);
    }

    public function attachLeadPamphlet(Listing $listing): void
    {
        if ($listing->isAgentListing() || $listing->publicPropertyType() === 'vacant_land'
            || ! in_array($listing->getStateCode(), ['MI', 'FL'], true)) {
            return;
        }

        $yearBuilt = $listing->answerValue('year_built');
        if (! is_numeric($yearBuilt) || (int) $yearBuilt >= 1978) {
            return;
        }

        $source = resource_path('documents/lead-pamphlet.pdf');
        if (! is_file($source) || $listing->documents()->where('type', self::LEAD_PAMPHLET_TYPE)->exists()) {
            return;
        }

        $path = "listings/{$listing->id}/documents/lead-pamphlet.pdf";
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, file_get_contents($source));

        $listing->documents()->create([
            'user_id' => $listing->user_id,
            'type' => self::LEAD_PAMPHLET_TYPE,
            'original_name' => 'Protect Your Family From Lead In Your Home.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => filesize($source),
            'status' => 'uploaded',
            'admin_notes' => 'EPA lead hazard pamphlet, added automatically for homes built before 1978.',
        ]);
    }

    /**
     * Create or refresh the listing's generated documents. Signed documents are never changed
     * or removed; unsigned documents that no longer apply are removed.
     */
    public function generateListingDocuments(Listing $listing): \Illuminate\Support\Collection
    {
        $listing->load(['answers', 'listingProcess', 'user', 'servicePackage']);

        if ($listing->isAgentListing()) {
            $this->removeUnsignedListingDocuments($listing);

            return collect();
        }

        $values = $listing->answerValues();
        $templates = $this->applicableTemplates($listing, $values);

        $documents = collect();
        foreach ($templates as $template) {
            $content = $this->renderTemplateContent($listing, $template, $values);
            $document = $listing->documents()->where('document_template_id', $template->id)->first();

            if (! $document) {
                $document = $listing->documents()->create([
                    'user_id' => $listing->user_id,
                    'type' => $template->code,
                    'document_template_id' => $template->id,
                    'content' => $content,
                    'original_name' => "{$template->name}.pdf",
                    'path' => "listings/{$listing->id}/generated/{$template->code}.html",
                    'mime_type' => 'application/pdf',
                    'size' => strlen($content),
                    'status' => 'unsigned',
                    'admin_notes' => 'Generated from the listing answers.',
                ]);
            } elseif ($document->status !== 'signed' && $document->content !== $content) {
                $document->update(['content' => $content, 'size' => strlen($content)]);
            }

            $documents->push($document);
        }

        $listing->documents()
            ->whereNotNull('document_template_id')
            ->whereNotIn('document_template_id', $templates->pluck('id'))
            ->where('status', '!=', 'signed')
            ->delete();

        return $documents;
    }

    /** Statutory document types of listings without disclosure steps. */
    public const STATUTORY_DOCUMENT_TYPES = ['sellers_disclosure', 'lead_disclosure', 'condo_disclosure', 'listing_agreement', 'agency_disclosure'];

    /**
     * Agent listings have no listing documents (original Reozom deletes them). Signed documents
     * and the files people uploaded are kept.
     */
    private function removeUnsignedListingDocuments(Listing $listing): void
    {
        $listing->documents()
            ->where('status', '!=', 'signed')
            ->where(fn ($q) => $q->whereNotNull('document_template_id')
                ->orWhereIn('type', self::STATUTORY_DOCUMENT_TYPES))
            ->delete();
    }

    /**
     * Re-render one generated document from the current answers. A signed document whose
     * content changed goes back to unsigned so it can be signed again.
     */
    public function regenerateDocument(Listing $listing, ListingDocument $document): ListingDocument
    {
        $template = $document->template;
        if (! $template) {
            return $document;
        }

        $listing->load(['answers', 'user', 'servicePackage']);
        $content = $this->renderTemplateContent($listing, $template, $listing->answerValues());

        if ($document->content !== $content) {
            $document->update([
                'content' => $content,
                'size' => strlen($content),
                'status' => 'unsigned',
                'signed_at' => null,
                'signed_by' => null,
                'signature_image' => null,
            ]);
        }

        return $document;
    }

    private function renderTemplateContent(Listing $listing, DocumentTemplate $template, array $values): string
    {
        if ($template->source === DocumentTemplate::SOURCE_DISCLOSURE) {
            return $this->renderDisclosureContent($listing, $template, $values);
        }

        $price = $listing->publicValue('listing_price');
        $placeholders = [
            '{{seller_name}}' => $listing->user?->name ?? 'Property Owner',
            '{{property_address}}' => trim(implode(', ', array_filter([$listing->address, $listing->city, $listing->state, $listing->zip]))),
            '{{list_price}}' => is_numeric($price) ? '$'.number_format((float) $price) : 'the agreed price',
            '{{package_name}}' => $listing->servicePackage?->name ?? 'selected',
            '{{reference_code}}' => (string) $listing->reference_code,
            '{{date}}' => now()->format('F j, Y'),
        ];

        return strtr((string) $template->body, array_map(fn ($v) => e($v), $placeholders));
    }

    /**
     * Questions and answers from the listing's own disclosure steps, so questions an agent
     * added or edited in their process appear in the document.
     */
    private function renderDisclosureContent(Listing $listing, DocumentTemplate $template, array $values): string
    {
        $codes = (array) $template->disclosure_codes;
        $html = '';

        foreach ($listing->activeSteps($values) as $step) {
            if (! ListingProcess::isDisclosureStep($step) || ! in_array($step['disclosure_code'], $codes, true)) {
                continue;
            }

            $html .= '<h3 style="margin:18px 0 6px;font-size:15px;">'.e($step['name'] ?? '').'</h3>';

            foreach ($step['sections'] ?? [] as $section) {
                $rows = '';
                foreach ($section['fields'] ?? [] as $field) {
                    if (($field['active'] ?? true) === false) {
                        continue;
                    }
                    if (! empty($field['showIf']) && ! ListingProcess::conditionMatches($field['showIf'], $values)) {
                        continue;
                    }

                    $rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;width:62%;">'.e($field['label'] ?? $field['name'] ?? '')
                        .'</td><td style="padding:6px 8px;border-bottom:1px solid #e2e8f0;font-weight:600;">'
                        .e($this->formatAnswer($field, $values[$field['name'] ?? $field['id'] ?? ''] ?? null)).'</td></tr>';
                }

                if ($rows !== '') {
                    $html .= '<h4 style="margin:12px 0 4px;font-size:13px;color:#334155;">'.e($section['name'] ?? '').'</h4>'
                        .'<table style="width:100%;border-collapse:collapse;font-size:12px;">'.$rows.'</table>';
                }
            }
        }

        return $html;
    }

    private function formatAnswer(array $field, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return 'Not answered';
        }

        $labels = array_column($field['options'] ?? [], 'label', 'value');
        $format = fn ($v) => $labels[(string) $v] ?? (is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v);

        return is_array($value) ? implode(', ', array_map($format, $value)) : $format($value);
    }
}
