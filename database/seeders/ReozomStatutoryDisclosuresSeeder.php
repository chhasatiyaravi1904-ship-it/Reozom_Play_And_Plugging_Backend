<?php

namespace Database\Seeders;

use App\Models\PropertyDisclosure;
use Illuminate\Database\Seeder;

class ReozomStatutoryDisclosuresSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Populates exact statutory property disclosures matching the Reozom platform specifications:
     * - Michigan Residential Seller's Disclosure Statement (MCL 565.957)
     * - Michigan Residential Appliances & Operating Systems (MCL 565.957)
     * - Federal Lead-Based Paint Hazard Disclosure (EPA / Title X)
     * - Michigan Vacant Land Seller's Disclosure (Land Division Act)
     * - Florida Single Family Seller's Property Disclosure
     * - Florida Condominium Seller's Property Disclosure
     * - Florida Vacant Land Seller's Property Disclosure
     */
    public function run(): void
    {
        $disclosures = [
            // 1. Michigan Residential Appliances & Operating Systems (Disclosure 1 in Reozom)
            [
                'code' => 'mi_residential_appliances',
                'title' => "Michigan Seller's Disclosure - Appliances & Operating Systems (MCL 565.957)",
                'state_code' => 'MI',
                'property_type' => 'residential',
                'category' => 'statutory',
                'order' => 1,
                'is_required' => true,
                'is_active' => true,
                'description' => "Statutory disclosure of appliances, operating systems, and mechanical fixtures in working order pursuant to the Michigan Seller Disclosure Act (MCL 565.957).",
                'schema' => [
                    'sections' => [
                        [
                            'id' => 'kitchen_appliances',
                            'name' => 'Kitchen & Major Household Appliances',
                            'order' => 1,
                            'fields' => [
                                $this->createApplianceField('range_oven', 'Range / Oven'),
                                $this->createApplianceField('dishwasher', 'Dishwasher'),
                                $this->createApplianceField('disposal', 'Garbage Disposal'),
                                $this->createApplianceField('hood_fan', 'Hood / Fan'),
                                $this->createApplianceField('microwave', 'Microwave'),
                                $this->createApplianceField('refrigerator', 'Refrigerator'),
                                $this->createApplianceField('trash_compactor', 'Trash Compactor'),
                            ],
                        ],
                        [
                            'id' => 'home_electronics_security',
                            'name' => 'Home Electronics, Security & Outdoor Systems',
                            'order' => 2,
                            'fields' => [
                                $this->createApplianceField('tv_controls', 'TV Antenna / Cable TV Wiring & Controls'),
                                $this->createApplianceField('electric_system', 'Electric System'),
                                $this->createApplianceField('garage_controls', 'Garage Door Opener & Remote Controls'),
                                $this->createApplianceField('alarm_system', 'Alarm / Security System'),
                                [
                                    'id' => 'security_system_status',
                                    'name' => 'security_system_status',
                                    'label' => 'Alarm / Security System Status',
                                    'type' => 'select',
                                    'required' => false,
                                    'options' => [
                                        ['label' => 'Owned', 'value' => 'owned'],
                                        ['label' => 'Leased', 'value' => 'leased'],
                                        ['label' => 'Not Applicable', 'value' => 'na'],
                                    ],
                                ],
                                $this->createApplianceField('intercom', 'Intercom System'),
                                $this->createApplianceField('central_vacuum', 'Central Vacuum System & Accessories'),
                                $this->createApplianceField('attic_fan', 'Attic Fan'),
                                $this->createApplianceField('ceiling_fan', 'Ceiling Fan'),
                                $this->createApplianceField('sauna', 'Sauna / Hot Tub & Equipment'),
                                $this->createApplianceField('pool_heater', 'Pool Heater, Wall Liner & Equipment'),
                                $this->createApplianceField('lawn_sprinkler_system', 'Lawn Sprinkler System'),
                            ],
                        ],
                        [
                            'id' => 'water_plumbing_climate',
                            'name' => 'Water, Plumbing & Climate Control Systems',
                            'order' => 3,
                            'fields' => [
                                $this->createApplianceField('washer', 'Washer'),
                                $this->createApplianceField('dryer', 'Dryer'),
                                $this->createApplianceField('water_heater', 'Water Heater'),
                                $this->createApplianceField('plumbing_system', 'Plumbing System'),
                                $this->createApplianceField('water_softener_conditioner', 'Water Softener / Conditioner'),
                                [
                                    'id' => 'water_softener_status',
                                    'name' => 'water_softener_status',
                                    'label' => 'Water Softener Status',
                                    'type' => 'select',
                                    'required' => false,
                                    'options' => [
                                        ['label' => 'Owned', 'value' => 'owned'],
                                        ['label' => 'Leased', 'value' => 'leased'],
                                        ['label' => 'Not Applicable', 'value' => 'na'],
                                    ],
                                ],
                                $this->createApplianceField('well_pump', 'Well Pump'),
                                $this->createApplianceField('sump_pump', 'Sump Pump'),
                                $this->createApplianceField('city_water_system', 'City Water System'),
                                $this->createApplianceField('city_sewer_system', 'City Sewer System'),
                                $this->createApplianceField('septic_tank_drain_field', 'Septic Tank & Drain Field'),
                                $this->createApplianceField('central_air_conditioning', 'Central Air Conditioning'),
                                $this->createApplianceField('central_heating_system', 'Central Heating System'),
                                $this->createApplianceField('wall_furnace', 'Wall Furnace'),
                                $this->createApplianceField('humidifier', 'Humidifier'),
                                $this->createApplianceField('electric_air_filter', 'Electronic Air Cleaner'),
                                $this->createApplianceField('solar_heating_system', 'Solar Heating System'),
                                $this->createApplianceField('fireplace_chimney', 'Fireplace & Chimney'),
                                $this->createApplianceField('wood_burning_system', 'Wood Burning System'),
                            ],
                        ],
                        [
                            'id' => 'appliances_explanation',
                            'name' => 'Deficiencies & Explanations',
                            'order' => 4,
                            'fields' => [
                                [
                                    'id' => 'explanation',
                                    'name' => 'explanation',
                                    'label' => 'Explanation of any items listed above that are not in working order:',
                                    'type' => 'textarea',
                                    'required' => false,
                                    'placeholder' => 'Specify which appliances/systems are inoperable and describe known defects or repair status...',
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // 2. Michigan Residential Property Conditions & Statutory Matters (Disclosure 2 in Reozom)
            [
                'code' => 'mi_residential_conditions',
                'title' => "Michigan Seller's Disclosure - Property Conditions & Legal (MCL 565.957)",
                'state_code' => 'MI',
                'property_type' => 'residential',
                'category' => 'statutory',
                'order' => 2,
                'is_required' => true,
                'is_active' => true,
                'description' => "Statutory representations regarding basement water, roof leaks, environmental hazards, structural integrity, zoning, and legal matters under MCL 565.957.",
                'schema' => [
                    'sections' => [
                        [
                            'id' => 'basement_roof_structural',
                            'name' => 'Basement, Roof & Structural Conditions',
                            'order' => 1,
                            'fields' => [
                                [
                                    'id' => 'basement_water_id',
                                    'name' => 'basement_water_id',
                                    'label' => 'Basement/Crawlspace: Has there been evidence of water?',
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
                                    'label' => 'If yes to basement water, please explain details, date, and remedies:',
                                    'type' => 'textarea',
                                    'required' => false,
                                    'placeholder' => 'Explain seepage, past sump failure, drain tile installation...',
                                ],
                                [
                                    'id' => 'insulation',
                                    'name' => 'insulation',
                                    'label' => 'Describe insulation (type, R-value, or enter N/A if unknown):',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. Fiberglass blown-in, R-38 in attic, Foam boards, N/A',
                                ],
                                [
                                    'id' => 'installed_uffi_id',
                                    'name' => 'installed_uffi_id',
                                    'label' => 'Urea Formaldehyde Foam Insulation (UFFI) is installed:',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'roof_leaks_id',
                                    'name' => 'roof_leaks_id',
                                    'label' => 'Roof Leaks: Are you aware of any current or past roof leaks?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'roof_leaks_explain',
                                    'name' => 'roof_leaks_explain',
                                    'label' => 'If yes to roof leaks, please explain location and repair history:',
                                    'type' => 'textarea',
                                    'required' => false,
                                    'placeholder' => 'Explain any past leaks and repair work performed...',
                                ],
                                [
                                    'id' => 'roof_age',
                                    'name' => 'roof_age',
                                    'label' => 'Approximate roof age (enter years or N/A if unknown):',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. 8 years (installed 2018), or N/A',
                                ],
                                [
                                    'id' => 'settling_id',
                                    'name' => 'settling_id',
                                    'label' => 'Settling, flooding, drainage, structural or grading problems?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'major_damage_id',
                                    'name' => 'major_damage_id',
                                    'label' => 'Major damage to property from fire, wind, floods, or landslides?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'modifications_id',
                                    'name' => 'modifications_id',
                                    'label' => 'Structural modifications, alterations, or repairs made without permits or licensed contractors?',
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
                            'id' => 'utilities_environmental',
                            'name' => 'Utilities, Well, Septic & Environmental Hazards',
                            'order' => 2,
                            'fields' => [
                                [
                                    'id' => 'well_info',
                                    'name' => 'well_info',
                                    'label' => 'Type of well; depth/diameter, age, and repair history, if known:',
                                    'type' => 'text',
                                    'required' => false,
                                    'placeholder' => 'e.g. Drilled 4-inch well, 120 ft deep, pump replaced in 2021, or N/A',
                                ],
                                [
                                    'id' => 'water_tested_id',
                                    'name' => 'water_tested_id',
                                    'label' => 'Has the well water been tested?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown / Municipal Water', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'date_report',
                                    'name' => 'date_report',
                                    'label' => 'Date of last well water test report (if applicable):',
                                    'type' => 'text',
                                    'required' => false,
                                    'placeholder' => 'e.g. 05-15-2023 or N/A',
                                ],
                                [
                                    'id' => 'water_results',
                                    'name' => 'water_results',
                                    'label' => 'Water test results summary:',
                                    'type' => 'textarea',
                                    'required' => false,
                                    'placeholder' => 'e.g. Potable, no coliform bacteria or nitrates detected...',
                                ],
                                [
                                    'id' => 'septic_tank',
                                    'name' => 'septic_tank',
                                    'label' => 'Condition of septic tank and drain field, if known:',
                                    'type' => 'text',
                                    'required' => false,
                                    'placeholder' => 'e.g. Pumped September 2023, functioning properly, or N/A',
                                ],
                                [
                                    'id' => 'heating_system',
                                    'name' => 'heating_system',
                                    'label' => 'Heating system type and approximate age:',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. Forced Air Natural Gas, installed 2019, 96% High-Efficiency',
                                ],
                                [
                                    'id' => 'di_plumbing_type_id',
                                    'name' => 'di_plumbing_type_id',
                                    'label' => 'Primary plumbing system material:',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Copper', 'value' => 'copper'],
                                        ['label' => 'PEX / Plastic', 'value' => 'pex_plastic'],
                                        ['label' => 'Galvanized Steel', 'value' => 'galvanized'],
                                        ['label' => 'Cast Iron / PVC', 'value' => 'pvc_cast_iron'],
                                        ['label' => 'Other / Mixed', 'value' => 'other'],
                                    ],
                                ],
                                [
                                    'id' => 'plumbing_problems',
                                    'name' => 'plumbing_problems',
                                    'label' => 'Plumbing: Any known defects, low water pressure, or leaks?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'plumbing_problems_explain',
                                    'name' => 'plumbing_problems_explain',
                                    'label' => 'If yes, please explain plumbing problems:',
                                    'type' => 'textarea',
                                    'required' => false,
                                ],
                                [
                                    'id' => 'electrical_system',
                                    'name' => 'electrical_system',
                                    'label' => 'Electrical system: Any known problems or knob-and-tube / aluminum wiring?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'electrical_system_explain',
                                    'name' => 'electrical_system_explain',
                                    'label' => 'If yes, please explain electrical problems:',
                                    'type' => 'textarea',
                                    'required' => false,
                                ],
                                [
                                    'id' => 'infestation',
                                    'name' => 'infestation',
                                    'label' => 'History of infestation, if any (termites, carpenter ants, bats, rodents):',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'infestation_explain',
                                    'name' => 'infestation_explain',
                                    'label' => 'If yes, please explain infestation history and eradication treatment:',
                                    'type' => 'textarea',
                                    'required' => false,
                                ],
                                [
                                    'id' => 'environmental_problems_id',
                                    'name' => 'environmental_problems_id',
                                    'label' => 'Aware of environmental hazards (asbestos, radon gas, mold, formaldehyde, contaminated soil)?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'environmental_explain',
                                    'name' => 'environmental_explain',
                                    'label' => 'If yes, please explain environmental hazards and test reports:',
                                    'type' => 'textarea',
                                    'required' => false,
                                ],
                                [
                                    'id' => 'storage_tanks_id',
                                    'name' => 'storage_tanks_id',
                                    'label' => 'Any underground fuel or chemical storage tanks?',
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
                            'id' => 'zoning_legal_statutory',
                            'name' => 'Zoning, HOA, Flood & Legal Matters',
                            'order' => 3,
                            'fields' => [
                                [
                                    'id' => 'flood_insurance_id',
                                    'name' => 'flood_insurance_id',
                                    'label' => 'Flood Insurance: Do you have flood insurance on the property?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'mineral_rights_id',
                                    'name' => 'mineral_rights_id',
                                    'label' => 'Mineral Rights: Do you own the mineral rights to the property?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'adjoining_id',
                                    'name' => 'adjoining_id',
                                    'label' => 'Features shared with adjoining landowners (walls, fences, driveways, private roads)?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'zoning_id',
                                    'name' => 'zoning_id',
                                    'label' => 'Any encroachments, easements, zoning violations, or nonconforming uses?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'common_areas_id',
                                    'name' => 'common_areas_id',
                                    'label' => 'Common areas (pools, tennis courts, clubhouses) or HOA with authority over property?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'farm_operations_id',
                                    'name' => 'farm_operations_id',
                                    'label' => 'Farm operations in vicinity, or proximity to a landfill, airport, or shooting range?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'utility_id',
                                    'name' => 'utility_id',
                                    'label' => 'Outstanding utility assessments or fees, including natural gas main surcharges?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'fees_id',
                                    'name' => 'fees_id',
                                    'label' => 'Outstanding municipal assessments or fees (paving, sidewalks, sewer connections)?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'litigation_id',
                                    'name' => 'litigation_id',
                                    'label' => 'Any pending litigation affecting the property or seller\'s right to convey?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'explain_answers',
                                    'name' => 'explain_answers',
                                    'label' => 'If the answer to any statutory questions above is yes, please explain details:',
                                    'type' => 'textarea',
                                    'required' => false,
                                    'placeholder' => 'Provide clear explanations for any affirmative answers above...',
                                ],
                            ],
                        ],
                        [
                            'id' => 'ownership_occupancy',
                            'name' => 'Seller Ownership & Occupancy History',
                            'order' => 4,
                            'fields' => [
                                [
                                    'id' => 'non_owner_occupied',
                                    'name' => 'non_owner_occupied',
                                    'label' => 'Non-owner occupied property (rental / investment property):',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No (Seller lives in the home)', 'value' => 'no'],
                                        ['label' => 'Yes (Non-owner occupied / Investor)', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'date_lived_start',
                                    'name' => 'date_lived_start',
                                    'label' => 'Seller has lived in the residence from (enter date or N/A):',
                                    'type' => 'text',
                                    'required' => false,
                                    'placeholder' => 'e.g. 06-15-2016 or N/A',
                                ],
                                [
                                    'id' => 'date_lived_end',
                                    'name' => 'date_lived_end',
                                    'label' => 'Seller has lived in the residence to (enter date or Present):',
                                    'type' => 'text',
                                    'required' => false,
                                    'placeholder' => 'e.g. Present or 01-10-2024',
                                ],
                                [
                                    'id' => 'date_owned',
                                    'name' => 'date_owned',
                                    'label' => 'The Seller has owned the property since (Date):',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. 05-20-2016',
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // 3. Federal Lead-Based Paint Hazard Disclosure (Applies nationally for pre-1978 homes)
            [
                'code' => 'lead_paint_disclosure',
                'title' => 'Lead-Based Paint and Lead-Based Paint Hazards Disclosure',
                'state_code' => 'ALL',
                'property_type' => 'residential',
                'category' => 'lead_paint',
                'order' => 3,
                'is_required' => true,
                'is_active' => true,
                'description' => "Federal Title X Residential Lead-Based Paint Hazard Reduction Act of 1992 disclosure required for all residential housing constructed prior to 1978.",
                'schema' => [
                    'sections' => [
                        [
                            'id' => 'lead_knowledge_section',
                            'name' => "Federal Lead Warning & Seller's Knowledge",
                            'order' => 1,
                            'fields' => [
                                [
                                    'id' => 'built_before_1978',
                                    'name' => 'built_before_1978',
                                    'label' => 'Was this residential dwelling constructed prior to 1978?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No (Built in 1978 or later - exempt from lead disclosure)', 'value' => 'no'],
                                        ['label' => 'Yes (Built prior to 1978 - Lead disclosure mandated)', 'value' => 'yes'],
                                        ['label' => 'Unknown year of construction', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'lead_knowledge_id',
                                    'name' => 'lead_knowledge_id',
                                    'label' => 'Presence of lead-based paint and/or lead-based paint hazards in the housing:',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Seller has no knowledge of lead-based paint and/or lead-based paint hazards in the housing', 'value' => 'no_knowledge'],
                                        ['label' => 'Known lead-based paint and/or lead-based paint hazards are present in the housing', 'value' => 'known_present'],
                                    ],
                                ],
                                [
                                    'id' => 'lead_explain',
                                    'name' => 'lead_explain',
                                    'label' => 'If known lead paint hazards are present, explain location and condition of surfaces:',
                                    'type' => 'textarea',
                                    'required' => false,
                                    'placeholder' => 'Explain location of lead-based paint and condition of painted surfaces...',
                                ],
                            ],
                        ],
                        [
                            'id' => 'lead_reports_section',
                            'name' => "Lead Paint Records & Reports",
                            'order' => 2,
                            'fields' => [
                                [
                                    'id' => 'lead_reports_id',
                                    'name' => 'lead_reports_id',
                                    'label' => 'Records and reports available to the seller pertaining to lead-based paint:',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Seller has no reports or records pertaining to lead-based paint in the housing', 'value' => 'no_reports'],
                                        ['label' => 'Seller has provided the purchaser with all available records and reports pertaining to lead-based paint', 'value' => 'has_reports'],
                                    ],
                                ],
                                [
                                    'id' => 'lead_documents',
                                    'name' => 'lead_documents',
                                    'label' => 'If reports are available, list the document titles and dates below:',
                                    'type' => 'textarea',
                                    'required' => false,
                                    'placeholder' => 'e.g. Lead Risk Assessment Report by Apex Environmental, June 2021...',
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // 4. Michigan Vacant Land Seller's Disclosure (q1 - q11 in Reozom)
            [
                'code' => 'mi_vacant_land_disclosure',
                'title' => "Michigan Vacant Land Seller's Disclosure Statement",
                'state_code' => 'MI',
                'property_type' => 'vacant_land',
                'category' => 'statutory',
                'order' => 1,
                'is_required' => true,
                'is_active' => true,
                'description' => "Mandatory Michigan statutory vacant land seller disclosure statement regarding boundary surveys, parcel divisions, zoning classification, PERC test, and soil/environmental conditions.",
                'schema' => [
                    'sections' => [
                        [
                            'id' => 'survey_boundaries',
                            'name' => 'Survey & Parcel Division History',
                            'order' => 1,
                            'fields' => [
                                [
                                    'id' => 'q1',
                                    'name' => 'q1',
                                    'label' => 'Has the property been surveyed?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'No', 'value' => 'no'],
                                    ],
                                ],
                                [
                                    'id' => 'q1a',
                                    'name' => 'q1a',
                                    'label' => 'If property has been surveyed, is a copy available for buyer inspection?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Yes (Survey copy on file)', 'value' => 'yes'],
                                        ['label' => 'No (Survey not available)', 'value' => 'no'],
                                    ],
                                ],
                                [
                                    'id' => 'q2',
                                    'name' => 'q2',
                                    'label' => 'Aware of any prior property divisions or splits involving this property since March 31, 1997 (Land Division Act)?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'id' => 'zoning_land_rights',
                            'name' => 'Zoning Classification & Mineral Rights',
                            'order' => 2,
                            'fields' => [
                                [
                                    'id' => 'q3',
                                    'name' => 'q3',
                                    'label' => 'Zoning classification of property:',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Known (Specify in notes)', 'value' => 'known'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'q4',
                                    'name' => 'q4',
                                    'label' => 'Aware of any encroachments, easements, zoning violations, or non-conforming uses?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'q5',
                                    'name' => 'q5',
                                    'label' => 'Aware of any mineral rights in property held by any person or entity other than yourself?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'id' => 'soil_perc_environmental',
                            'name' => 'Soil, PERC Test & Environmental Conditions',
                            'order' => 3,
                            'fields' => [
                                [
                                    'id' => 'q6',
                                    'name' => 'q6',
                                    'label' => 'Aware of any flooding, drainage, or grading problems?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'q7',
                                    'name' => 'q7',
                                    'label' => 'Has property ever had a "PERC TEST"?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'q7a',
                                    'name' => 'q7a',
                                    'label' => 'If so, is a copy of the PERC test report available?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Yes (PERC report available)', 'value' => 'yes'],
                                        ['label' => 'No (Report not available)', 'value' => 'no'],
                                    ],
                                ],
                                [
                                    'id' => 'q8',
                                    'name' => 'q8',
                                    'label' => 'Aware of any underground storage tanks presently on property or previously removed?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'q9',
                                    'name' => 'q9',
                                    'label' => 'Aware of any ground water contamination?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'q10',
                                    'name' => 'q10',
                                    'label' => 'Aware of any other environmental contamination on the property?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'q11',
                                    'name' => 'q11',
                                    'label' => 'Received notice of environmental regulation or condition (such as wetland determination) prohibiting or restricting property use?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'id' => 'additional_ownership',
                            'name' => 'Material Information & Ownership Duration',
                            'order' => 4,
                            'fields' => [
                                [
                                    'id' => 'explanations',
                                    'name' => 'explanations',
                                    'label' => 'Is there any other material information that the Buyer should be aware of, please explain:',
                                    'type' => 'textarea',
                                    'required' => false,
                                    'placeholder' => 'Enter any additional disclosures, easements, deed restrictions, or soil conditions...',
                                ],
                                [
                                    'id' => 'date_owned',
                                    'name' => 'date_owned',
                                    'label' => 'You have owned the property since (Date):',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. 05-15-2018',
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // 5. Florida Single Family Residential Property Disclosure
            [
                'code' => 'fl_residential_single_family',
                'title' => "Florida Single Family Seller's Property Disclosure",
                'state_code' => 'FL',
                'property_type' => 'residential',
                'category' => 'statutory',
                'order' => 1,
                'is_required' => true,
                'is_active' => true,
                'description' => "Statutory Florida Association of Realtors disclosure covering claims, HOA deed restrictions, sinkholes, flood hazard zones, termites, roof, pool safety, and mechanical systems.",
                'schema' => [
                    // Original Reozom shows this form only for Single Family (property_sub_type = 1)
                    'step_show_if' => ['field' => 'property_sub_type', 'equals' => '1'],
                    'sections' => [
                        [
                            'id' => 'fl_claims_restrictions',
                            'name' => 'Claims, Assessments & Deed Restrictions',
                            'order' => 1,
                            'fields' => [
                                [
                                    'id' => 'field_1',
                                    'name' => 'field_1',
                                    'label' => 'Aware of existing, pending, or proposed legal actions, claims, special assessments, or unpaid HOA maintenance fees?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'field_2',
                                    'name' => 'field_2',
                                    'label' => 'If yes, please explain pending actions or assessments:',
                                    'type' => 'textarea',
                                    'required' => false,
                                ],
                                [
                                    'id' => 'field_3',
                                    'name' => 'field_3',
                                    'label' => 'Have any local, state, or federal authorities notified you that repairs, alterations, or corrections are required?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'field_5',
                                    'name' => 'field_5',
                                    'label' => 'Are there any deed or homeowner association restrictions?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    // Same key as the original Reozom (field_11): "Yes" adds the HOA Disclosure document
                                    'id' => 'field_11',
                                    'name' => 'field_11',
                                    'label' => "Is membership in a homeowners' association mandatory?",
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'field_8',
                                    'name' => 'field_8',
                                    'label' => 'Are there any restrictions on leasing the property?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'id' => 'fl_land_sinkholes_flood',
                            'name' => 'The Land, Sinkholes & Flood Hazard Zones',
                            'order' => 2,
                            'fields' => [
                                [
                                    'id' => 'field_23',
                                    'name' => 'field_23',
                                    'label' => 'Aware of settling, soil movement, erosion, or drainage problems affecting the property?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'field_24',
                                    'name' => 'field_24',
                                    'label' => 'Has any past or present sinkhole activity occurred on the property or insurance claims made for sinkholes?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'field_25',
                                    'name' => 'field_25',
                                    'label' => 'If yes to sinkhole activity, explain past underpinning, grouting, or insurance claims:',
                                    'type' => 'textarea',
                                    'required' => false,
                                ],
                                [
                                    'id' => 'field_26',
                                    'name' => 'field_26',
                                    'label' => 'Aware of environmental hazards such as radon gas, toxic mold, asbestos, or wetlands?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'field_30',
                                    'name' => 'field_30',
                                    'label' => 'Is property located in a Special Flood Hazard Area or flood zone requiring flood insurance?',
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
                            'id' => 'fl_roof_pests_pool',
                            'name' => 'Pests, Roof, Pools & Mechanical Systems',
                            'order' => 3,
                            'fields' => [
                                [
                                    'id' => 'field_33',
                                    'name' => 'field_33',
                                    'label' => 'History of termites, dry rot, pests, or wood destroying organisms?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'field_39',
                                    'name' => 'field_39',
                                    'label' => 'Approximate roof age (years or installation date):',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. 6 years (Installed 2020)',
                                ],
                                [
                                    'id' => 'field_40',
                                    'name' => 'field_40',
                                    'label' => 'Any current or past roof leaks?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'field_45',
                                    'name' => 'field_45',
                                    'label' => 'Any known plumbing problems or polybutylene piping on the property?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'field_51',
                                    'name' => 'field_51',
                                    'label' => 'Is there a swimming pool, hot tub, or spa on the property?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'field_53',
                                    'name' => 'field_53',
                                    'label' => 'Does pool comply with Florida Residential Swimming Pool Safety Act barrier requirements?',
                                    'type' => 'select',
                                    'required' => false,
                                    'options' => [
                                        ['label' => 'Yes (Screen enclosure, fence, or pool alarms present)', 'value' => 'yes'],
                                        ['label' => 'No / Non-compliant', 'value' => 'no'],
                                        ['label' => 'Not Applicable (No pool)', 'value' => 'na'],
                                    ],
                                ],
                                [
                                    'id' => 'field_60',
                                    'name' => 'field_60',
                                    'label' => 'Central Air Conditioning approximate age and operational condition:',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. 4 years old (Replaced 2022), fully operational',
                                ],
                            ],
                        ],
                        [
                            'id' => 'fl_occupancy_history',
                            'name' => 'Seller Occupancy & Ownership Details',
                            'order' => 4,
                            'fields' => [
                                [
                                    'id' => 'field_125',
                                    'name' => 'field_125',
                                    'label' => 'Does the Seller currently occupy the property?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Yes (Owner-occupied primary residence)', 'value' => 'yes'],
                                        ['label' => 'No (Tenant-occupied / Vacant / Investment)', 'value' => 'no'],
                                    ],
                                ],
                                [
                                    'id' => 'field_124',
                                    'name' => 'field_124',
                                    'label' => 'Date Seller acquired title to the property:',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. 04-12-2019',
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            // 6. Florida Condominium Seller's Property Disclosure
            [
                'code' => 'fl_residential_condo',
                'title' => "Florida Condominium Seller's Property Disclosure",
                'state_code' => 'FL',
                'property_type' => 'condo',
                'category' => 'statutory',
                'order' => 1,
                'is_required' => true,
                'is_active' => true,
                'description' => "Statutory Florida Condominium disclosure covering HOA fees, reserve studies, Florida SB 4-D Milestone structural inspections, and unit rules.",
                'schema' => [
                    // Original Reozom shows this form only for Condominiums (property_sub_type = 2)
                    'step_show_if' => ['field' => 'property_sub_type', 'equals' => '2'],
                    'sections' => [
                        [
                            'id' => 'condo_association_governance',
                            'name' => 'Condominium Association & Reserve Studies',
                            'order' => 1,
                            'fields' => [
                                [
                                    'id' => 'condo_assoc_name',
                                    'name' => 'condo_assoc_name',
                                    'label' => 'Condominium Association Name and Management Contact:',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. Bayview Towers Condominium Association, Inc.',
                                ],
                                [
                                    'id' => 'condo_fee_amount',
                                    'name' => 'condo_fee_amount',
                                    'label' => 'Current monthly condominium maintenance fee amount ($):',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. $650 / month',
                                ],
                                [
                                    'id' => 'condo_special_assessments',
                                    'name' => 'condo_special_assessments',
                                    'label' => 'Are there any pending or approved special assessments?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'condo_special_explain',
                                    'name' => 'condo_special_explain',
                                    'label' => 'If yes, explain special assessment amount and purpose:',
                                    'type' => 'textarea',
                                    'required' => false,
                                    'placeholder' => 'e.g. Roof replacement assessment $4,500 payable in quarterly installments...',
                                ],
                                [
                                    'id' => 'condo_milestone_inspection',
                                    'name' => 'condo_milestone_inspection',
                                    'label' => 'Has building completed Florida SB 4-D Milestone Structural Inspection (if 3+ stories)?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Completed - Passed with no substantial structural defects', 'value' => 'passed'],
                                        ['label' => 'Phase 2 Inspection In Progress or Scheduled', 'value' => 'in_progress'],
                                        ['label' => 'Not Applicable (Building under 3 stories / Exempt)', 'value' => 'exempt'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'id' => 'condo_rules_parking',
                            'name' => 'Condo Rules, Pet Policy & Parking Assignments',
                            'order' => 2,
                            'fields' => [
                                [
                                    'id' => 'condo_pet_rules',
                                    'name' => 'condo_pet_rules',
                                    'label' => 'Pet restrictions (number, weight limit, breed restrictions):',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. Maximum 2 pets, under 35 lbs each',
                                ],
                                [
                                    'id' => 'condo_rental_rules',
                                    'name' => 'condo_rental_rules',
                                    'label' => 'Leasing / rental restrictions (minimum lease duration):',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. Minimum 6 months lease, 1 lease per year, board approval required',
                                ],
                                [
                                    'id' => 'condo_parking_spaces',
                                    'name' => 'condo_parking_spaces',
                                    'label' => 'Assigned parking space number(s) and location:',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. Space #42 Covered Garage + Guest Pass',
                                ],
                            ],
                        ],
                        [
                            'id' => 'condo_interior_condition',
                            'name' => 'Unit Interior Conditions & Leaks',
                            'order' => 3,
                            'fields' => [
                                [
                                    'id' => 'condo_water_leaks',
                                    'name' => 'condo_water_leaks',
                                    'label' => 'Past or present water leaks from upper units, common pipes, or HVAC air handlers?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'condo_unapproved_alterations',
                                    'name' => 'condo_unapproved_alterations',
                                    'label' => 'Any interior alterations or flooring changes made without required association architectural approval?',
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
            ],

            // 7. Florida Vacant Land Seller's Property Disclosure
            [
                'code' => 'fl_vacant_land_disclosure',
                'title' => "Florida Vacant Land Seller's Property Disclosure",
                'state_code' => 'FL',
                'property_type' => 'vacant_land',
                'category' => 'statutory',
                'order' => 1,
                'is_required' => true,
                'is_active' => true,
                'description' => "Florida vacant land disclosure statement regarding zoning classifications, wetlands, soil conditions, endangered species, utilities, and access easements.",
                'schema' => [
                    'sections' => [
                        [
                            'id' => 'fl_vl_claims_restrictions',
                            'name' => 'Claims, Eminent Domain & Restrictions',
                            'order' => 1,
                            'fields' => [
                                [
                                    'id' => 'field_1',
                                    'name' => 'field_1',
                                    'label' => 'Aware of existing, pending, or proposed legal actions or special assessments affecting the parcel?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'field_5',
                                    'name' => 'field_5',
                                    'label' => 'Aware of any eminent domain or condemnation proceedings involving the property?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                    ],
                                ],
                                [
                                    'id' => 'field_7',
                                    'name' => 'field_7',
                                    'label' => 'Are there any subdivision, municipality or other recorded covenants, conditions or restrictions?',
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
                            'id' => 'fl_vl_soils_wetlands',
                            'name' => 'Soils, Wetlands & Protected Wildlife',
                            'order' => 2,
                            'fields' => [
                                [
                                    'id' => 'soil_perc_test',
                                    'name' => 'soil_perc_test',
                                    'label' => 'Has a soil test or PERC test been performed on the property?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Yes (Report on file)', 'value' => 'yes'],
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'flood_wetlands',
                                    'name' => 'flood_wetlands',
                                    'label' => 'Is any portion of the parcel located in a designated flood zone, wetland, or conservation area?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'No', 'value' => 'no'],
                                        ['label' => 'Yes', 'value' => 'yes'],
                                        ['label' => 'Unknown', 'value' => 'unknown'],
                                    ],
                                ],
                                [
                                    'id' => 'wildlife_habitat',
                                    'name' => 'wildlife_habitat',
                                    'label' => 'Aware of any protected wildlife species (gopher tortoise, scrub jays) or nests on the parcel?',
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
                            'id' => 'fl_vl_access_utilities',
                            'name' => 'Legal Access, Utilities & Ownership',
                            'order' => 3,
                            'fields' => [
                                [
                                    'id' => 'road_access',
                                    'name' => 'road_access',
                                    'label' => 'Legal access to parcel (Paved public road, unpaved road, or private recorded easement):',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. Direct frontage on paved public county road',
                                ],
                                [
                                    'id' => 'electric_utility',
                                    'name' => 'electric_utility',
                                    'label' => 'Is electric utility service available at the parcel boundary?',
                                    'type' => 'select',
                                    'required' => true,
                                    'options' => [
                                        ['label' => 'Yes (Service at street / boundary)', 'value' => 'yes'],
                                        ['label' => 'No (Extension required)', 'value' => 'no'],
                                    ],
                                ],
                                [
                                    'id' => 'water_sewer_type',
                                    'name' => 'water_sewer_type',
                                    'label' => 'Water and sewer availability (Municipal hookup vs Well & Septic needed):',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. Municipal water at street; private septic system required',
                                ],
                                [
                                    'id' => 'date_owned',
                                    'name' => 'date_owned',
                                    'label' => 'Date Seller acquired title to the parcel:',
                                    'type' => 'text',
                                    'required' => true,
                                    'placeholder' => 'e.g. 10-24-2017',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($disclosures as $data) {
            PropertyDisclosure::updateOrCreate(
                ['code' => $data['code']],
                $data
            );
        }

        $this->command?->info('Reozom statutory property disclosures successfully seeded into database.');
    }

    /**
     * Helper to create standard Michigan MCL 565.957 statutory appliance fields.
     */
    private function createApplianceField(string $id, string $label): array
    {
        return [
            'id' => $id,
            'name' => $id,
            'label' => $label,
            'type' => 'select',
            'required' => true,
            'options' => [
                ['label' => 'Yes (In Working Order)', 'value' => 'yes'],
                ['label' => 'No (Not Working)', 'value' => 'no'],
                ['label' => 'Unknown', 'value' => 'unknown'],
                ['label' => 'Not Included / Not Available', 'value' => 'na'],
            ],
        ];
    }
}

