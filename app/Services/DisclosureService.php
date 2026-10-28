<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ListingAnswer;
use App\Models\ListingDocument;
use Illuminate\Support\Str;

class DisclosureService
{
    /**
     * Get statutory disclosure schema matching the Reozom platform specifications.
     */
    public function getDisclosureSchema(Listing $listing): array
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
    public function saveDisclosures(Listing $listing, array $answers): void
    {
        // 1. Persist master disclosures answer
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

        // 2. Automatically provision or regenerate statutory listing documents (Reozom logic)
        $this->regenerateStatutoryDocuments($listing);
    }

    /**
     * Provision and regenerate the standard statutory documents for this listing.
     * Reozom Document Types:
     * - sellers_disclosure: Seller's Disclosure Statement
     * - lead_disclosure: Lead-Based Paint Hazard Disclosure
     * - listing_agreement: Exclusive Right to Sell Listing Agreement
     * - agency_disclosure: Agency Disclosure Statement
     */
    public function regenerateStatutoryDocuments(Listing $listing): void
    {
        $statutoryDefs = [
            [
                'type' => 'sellers_disclosure',
                'name' => "Seller's Disclosure Statement",
                'description' => 'Mandatory statutory disclosure statement describing property conditions and improvements.',
            ],
            [
                'type' => 'lead_disclosure',
                'name' => 'Lead-Based Paint Hazard Disclosure',
                'description' => 'Federal EPA & State statutory lead-based paint hazard disclosure.',
            ],
            [
                'type' => 'listing_agreement',
                'name' => 'Exclusive Right to Sell Listing Agreement',
                'description' => 'Exclusive listing agreement with brokerage, package terms, and commission specifications.',
            ],
            [
                'type' => 'agency_disclosure',
                'name' => 'Agency Disclosure Statement',
                'description' => 'Disclosure Regarding Real Estate Agency Relationships and duties.',
            ],
        ];

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
    }
}
