<?php

namespace Database\Seeders;

use App\Models\PropertyDisclosure;
use App\Services\DisclosureService;
use Illuminate\Database\Seeder;

class PropertyDisclosureSeeder extends Seeder
{
    public function run(): void
    {
        $service = new DisclosureService();
        $defaultSteps = $service->getDefaultDisclosureSchema();

        foreach ($defaultSteps as $idx => $step) {
            PropertyDisclosure::updateOrCreate(
                ['code' => $step['id']],
                [
                    'title' => $step['name'],
                    'state_code' => null, // Applicable to all states by default
                    'property_type' => 'residential',
                    'category' => $step['id'] === 'lead_paint_disclosure' ? 'lead_paint' : 'statutory',
                    'description' => "Statutory disclosure requirement for {$step['name']}.",
                    'order' => $step['order'] ?? ($idx + 1),
                    'is_required' => true,
                    'is_active' => true,
                    'schema' => [
                        'sections' => $step['sections'] ?? [],
                    ],
                ]
            );
        }

        // Add a Michigan-specific Vacant Land Disclosure
        PropertyDisclosure::updateOrCreate(
            ['code' => 'mi_vacant_land_statutory'],
            [
                'title' => 'Michigan Vacant Land Environmental & Perk Disclosure',
                'state_code' => 'MI',
                'property_type' => 'vacant_land',
                'category' => 'statutory',
                'description' => 'Mandatory Michigan statutory disclosure for unimproved acreage, zoning, soil perk tests, and utility easements.',
                'order' => 5,
                'is_required' => true,
                'is_active' => true,
                'schema' => [
                    'sections' => [
                        [
                            'id' => 'land_soil_zoning',
                            'name' => 'Soil, Zoning & Perc Testing',
                            'order' => 1,
                            'fields' => [
                                [
                                    'id' => 'perc_test_conducted',
                                    'name' => 'perc_test_conducted',
                                    'label' => 'Has a soil perc test been conducted on the parcel for septic disposal?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Yes - Passed', 'value' => 'passed'],
                                        ['label' => 'Yes - Failed / Engineered System Required', 'value' => 'failed'],
                                        ['label' => 'No perc test conducted', 'value' => 'none'],
                                        ['label' => 'Municipal sewer available', 'value' => 'municipal'],
                                    ],
                                ],
                                [
                                    'id' => 'mineral_rights_included',
                                    'name' => 'mineral_rights_included',
                                    'label' => 'Are subsurface mineral, gas, and oil rights included in this sale?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Yes - 100% Mineral Rights Included', 'value' => 'yes_included'],
                                        ['label' => 'No - Previously Severed / Retained', 'value' => 'no_severed'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'wetlands_determination',
                                    'name' => 'wetlands_determination',
                                    'label' => 'Are there any designated state/federal regulated wetlands or flood zones?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No wetlands present', 'value' => 'no'],
                                        ['label' => 'Yes - Regulated wetlands on property', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }
}

