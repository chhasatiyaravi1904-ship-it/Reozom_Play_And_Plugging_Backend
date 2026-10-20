<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;
use App\Models\ListingProcess;
use App\Http\Requests\SaveListingProcessRequest;
use Database\Seeders\ListingProcessSeeder;

class ListingProcessSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_default_listing_processes_successfully()
    {
        $this->seed(ListingProcessSeeder::class);

        $residential = ListingProcess::where('name', 'Residential Property Listing Process')->first();
        $this->assertNotNull($residential, 'Residential Property Listing Process must exist in database.');
        $this->assertEquals('default', $residential->type);
        $this->assertEquals('active', $residential->status);
        $this->assertNull($residential->agent_id);
        $this->assertIsArray($residential->config);

        $vacantLand = ListingProcess::where('name', 'Vacant Land Listing Process')->first();
        $this->assertNotNull($vacantLand, 'Vacant Land Listing Process must exist in database.');
        $this->assertEquals('default', $vacantLand->type);
        $this->assertEquals('active', $vacantLand->status);
        $this->assertNull($vacantLand->agent_id);
        $this->assertIsArray($vacantLand->config);
    }

    public function test_it_is_idempotent_and_does_not_create_duplicate_records()
    {
        // First run
        $this->seed(ListingProcessSeeder::class);
        $firstCount = ListingProcess::count();

        // Second run
        $this->seed(ListingProcessSeeder::class);
        $secondCount = ListingProcess::count();

        $this->assertEquals($firstCount, $secondCount, 'Running seeder multiple times must not create duplicate processes.');

        $this->assertEquals(1, ListingProcess::where('name', 'Residential Property Listing Process')->count());
        $this->assertEquals(1, ListingProcess::where('name', 'Vacant Land Listing Process')->count());
    }

    public function test_it_has_correct_step_structure_for_residential_and_vacant_land()
    {
        $this->seed(ListingProcessSeeder::class);

        $residential = ListingProcess::where('name', 'Residential Property Listing Process')->first();
        $stepsRes = $residential->config;
        $this->assertCount(9, $stepsRes, 'Residential process must contain exactly 9 steps.');

        // Step 1: System Step
        $this->assertEquals('system_public_info', $stepsRes[0]['id']);
        $this->assertEquals('public_listing', $stepsRes[0]['type']);
        $this->assertTrue($stepsRes[0]['isSystem']);
        $this->assertEquals(1, $stepsRes[0]['order']);

        // Check sequential ordering of steps
        $expectedResSteps = [
            'system_public_info',
            'step_property_type_address',
            'step_property_location',
            'step_property_information',
            'step_property_features',
            'step_room_data',
            'step_disclosures_appliances',
            'step_disclosures_property',
            'step_images_virtual_tours',
        ];
        $actualResSteps = array_column($stepsRes, 'id');
        $this->assertEquals($expectedResSteps, $actualResSteps);

        $vacantLand = ListingProcess::where('name', 'Vacant Land Listing Process')->first();
        $stepsLand = $vacantLand->config;
        $this->assertCount(7, $stepsLand, 'Vacant Land process must contain exactly 7 steps.');

        // Step 1: System Step
        $this->assertEquals('system_public_info', $stepsLand[0]['id']);
        $this->assertEquals('public_listing', $stepsLand[0]['type']);
        $this->assertTrue($stepsLand[0]['isSystem']);
        $this->assertEquals(1, $stepsLand[0]['order']);

        $expectedLandSteps = [
            'system_public_info',
            'step_property_type_address',
            'step_property_location',
            'step_property_information',
            'step_improvements_features',
            'step_disclosures',
            'step_images_virtual_tours',
        ];
        $actualLandSteps = array_column($stepsLand, 'id');
        $this->assertEquals($expectedLandSteps, $actualLandSteps);
    }

    public function test_it_has_valid_field_dependencies_referencing_existing_fields()
    {
        $this->seed(ListingProcessSeeder::class);

        $processes = ListingProcess::whereIn('name', [
            'Residential Property Listing Process',
            'Vacant Land Listing Process',
        ])->get();

        foreach ($processes as $process) {
            $fieldIds = [];

            // First pass: collect all field IDs
            foreach ($process->config as $step) {
                foreach ($step['sections'] ?? [] as $section) {
                    foreach ($section['fields'] ?? [] as $field) {
                        $this->assertNotEmpty($field['id'], "Field in {$process->name} must have an ID.");
                        $this->assertNotEmpty($field['name'], "Field in {$process->name} must have a name.");
                        $this->assertNotEmpty($field['type'], "Field in {$process->name} must have a type.");
                        $fieldIds[$field['id']] = true;
                    }
                }
            }

            // Second pass: verify dependency parentFieldId exists
            foreach ($process->config as $step) {
                foreach ($step['sections'] ?? [] as $section) {
                    foreach ($section['fields'] ?? [] as $field) {
                        if (!empty($field['dependencies'])) {
                            foreach ($field['dependencies'] as $dep) {
                                $this->assertArrayHasKey(
                                    $dep['parentFieldId'],
                                    $fieldIds,
                                    "Field '{$field['id']}' in '{$process->name}' references non-existent parentFieldId '{$dep['parentFieldId']}'."
                                );
                                $this->assertContains(
                                    $dep['condition'],
                                    ['equals', 'contains', 'checked'],
                                    "Dependency in field '{$field['id']}' has invalid condition '{$dep['condition']}'."
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    public function test_it_passes_save_listing_process_request_validation()
    {
        $this->seed(ListingProcessSeeder::class);

        $rules = (new SaveListingProcessRequest())->rules();

        $processes = ListingProcess::whereIn('name', [
            'Residential Property Listing Process',
            'Vacant Land Listing Process',
        ])->get();

        foreach ($processes as $process) {
            $data = [
                'name' => $process->name,
                'type' => $process->type,
                'status' => $process->status,
                'config' => $process->config,
            ];

            $validator = Validator::make($data, $rules);

            $this->assertTrue(
                $validator->passes(),
                "Validation failed for '{$process->name}': " . json_encode($validator->errors()->all())
            );
        }
    }
}
