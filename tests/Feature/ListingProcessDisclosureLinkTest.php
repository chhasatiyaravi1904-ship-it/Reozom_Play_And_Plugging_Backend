<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingAnswer;
use App\Models\ListingProcess;
use App\Models\PropertyDisclosure;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\DisclosureService;
use Database\Seeders\ListingProcessDefinitions;
use Database\Seeders\ReozomStatutoryDisclosuresSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingProcessDisclosureLinkTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $seller;
    protected User $agent;
    protected ListingProcess $process;
    protected ServicePackage $package;
    protected DisclosureService $disclosureService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->seller = User::factory()->create(['role' => 'seller']);
        $this->agent = User::factory()->create(['role' => 'agent']);
        $this->disclosureService = app(DisclosureService::class);

        // Seed Reozom statutory disclosures
        $this->seed(ReozomStatutoryDisclosuresSeeder::class);

        // Create an Agent Service Package
        $this->package = ServicePackage::create([
            'agent_id' => $this->agent->id,
            'name' => 'Michigan Premium Full Service Package',
            'price' => 995,
            'status' => 'active',
            'package_type' => 'full_service',
            'target_zips' => ['48126', '48104'],
            'service_type' => 'residential',
        ]);

        // Create a Listing Process linked with specific required disclosures
        $this->process = ListingProcess::create([
            'name' => 'Custom Michigan Residential Process',
            'agent_id' => $this->agent->id,
            'service_package_id' => $this->package->id,
            'type' => 'custom',
            'status' => 'active',
            'assigned_zips' => ['48126'],
            'required_disclosures' => [
                'mi_residential_appliances',
                'mi_residential_conditions',
                'lead_paint_disclosure',
            ],
            'config' => ListingProcessDefinitions::getResidentialProcessConfig(),
        ]);
    }

    public function test_listing_creation_links_process_required_disclosures(): void
    {
        $this->mock(\App\Services\PackageAvailabilityService::class, function ($mock) {
            $mock->shouldReceive('validatePackageForZip')->andReturn($this->package);
        });

        $response = $this->actingAs($this->seller)->postJson('/api/listings', [
            'packageId' => $this->package->id,
            'zip' => '48126',
            'address' => '14520 Michigan Ave',
            'city' => 'Dearborn',
            'state' => 'MI',
        ]);

        $response->assertStatus(201);
        $listingId = $response->json('data.id');
        $listing = Listing::find($listingId);

        $this->assertEquals($this->process->id, $listing->listing_process_id);

        // Verify required_disclosures answer was automatically seeded from the process
        $answer = $listing->answers()->where('step_id', 'required_disclosures')->first();
        $this->assertNotNull($answer);
        $this->assertEquals([
            'mi_residential_appliances',
            'mi_residential_conditions',
            'lead_paint_disclosure',
        ], $answer->values['selected_codes']);
    }

    public function test_get_disclosures_returns_available_and_selected_documents(): void
    {
        $listing = Listing::create([
            'user_id' => $this->seller->id,
            'listing_process_id' => $this->process->id,
            'address' => '14520 Michigan Ave',
            'city' => 'Dearborn',
            'state' => 'MI',
            'zip' => '48126',
            'status' => 'in_progress',
        ]);

        ListingAnswer::create([
            'listing_id' => $listing->id,
            'step_id' => 'system_public_info',
            'values' => ['property_type' => 'single_family'],
        ]);

        $res = $this->actingAs($this->seller)->getJson("/api/listings/{$listing->id}/disclosures");
        $res->assertStatus(200);

        $data = $res->json('data');
        $this->assertArrayHasKey('availableDisclosures', $data);
        $this->assertArrayHasKey('selectedDisclosures', $data);
        $this->assertArrayHasKey('steps', $data);

        // Available disclosures for MI residential should include appliances, conditions, lead paint
        $availableCodes = array_column($data['availableDisclosures'], 'code');
        $this->assertContains('mi_residential_appliances', $availableCodes);
        $this->assertContains('mi_residential_conditions', $availableCodes);
        $this->assertContains('lead_paint_disclosure', $availableCodes);

        // Step questions match selected disclosures
        $stepIds = array_column($data['steps'], 'id');
        $this->assertContains('mi_residential_appliances', $stepIds);
        $this->assertContains('mi_residential_conditions', $stepIds);
        $this->assertContains('lead_paint_disclosure', $stepIds);
    }

    public function test_user_can_select_and_unselect_optional_disclosure_documents(): void
    {
        $listing = Listing::create([
            'user_id' => $this->seller->id,
            'listing_process_id' => $this->process->id,
            'address' => '14520 Michigan Ave',
            'city' => 'Dearborn',
            'state' => 'MI',
            'zip' => '48126',
            'status' => 'in_progress',
        ]);

        ListingAnswer::create([
            'listing_id' => $listing->id,
            'step_id' => 'system_public_info',
            'values' => ['property_type' => 'single_family'],
        ]);

        // Seller deselects lead paint disclosure (e.g. house built in 2012, exempt from Title X)
        $selectRes = $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/disclosures/select", [
            'selectedDisclosures' => [
                'mi_residential_appliances',
                'mi_residential_conditions',
            ],
        ]);

        $selectRes->assertStatus(200);
        $this->assertEquals([
            'mi_residential_appliances',
            'mi_residential_conditions',
        ], $selectRes->json('data.selectedDisclosures'));

        // Verify steps in response no longer contains lead_paint_disclosure
        $stepIds = array_column($selectRes->json('data.steps'), 'id');
        $this->assertContains('mi_residential_appliances', $stepIds);
        $this->assertContains('mi_residential_conditions', $stepIds);
        $this->assertNotContains('lead_paint_disclosure', $stepIds);

        // Verify persisted in database
        $this->assertEquals([
            'mi_residential_appliances',
            'mi_residential_conditions',
        ], $listing->answers()->where('step_id', 'required_disclosures')->first()->values['selected_codes']);
    }

    public function test_saving_disclosures_generates_documents_only_for_selected_disclosures(): void
    {
        $listing = Listing::create([
            'user_id' => $this->seller->id,
            'listing_process_id' => $this->process->id,
            'address' => '14520 Michigan Ave',
            'city' => 'Dearborn',
            'state' => 'MI',
            'zip' => '48126',
            'status' => 'in_progress',
        ]);

        ListingAnswer::create([
            'listing_id' => $listing->id,
            'step_id' => 'system_public_info',
            'values' => ['property_type' => 'single_family'],
        ]);

        // Save disclosures with ONLY appliances & conditions (excluding lead paint)
        $saveRes = $this->actingAs($this->seller)->postJson("/api/listings/{$listing->id}/disclosures", [
            'selectedDisclosures' => [
                'mi_residential_appliances',
                'mi_residential_conditions',
            ],
            'answers' => [
                'mi_residential_appliances' => [
                    'range_oven' => 'yes',
                    'refrigerator' => 'yes',
                ],
                'mi_residential_conditions' => [
                    'roof_age' => '5 years',
                    'basement_water_id' => 'no',
                ],
            ],
        ]);

        $saveRes->assertStatus(200);

        // Sellers disclosure should be generated
        $this->assertDatabaseHas('listing_documents', [
            'listing_id' => $listing->id,
            'type' => 'sellers_disclosure',
        ]);

        // Lead disclosure document should NOT be generated because it was unselected
        $this->assertDatabaseMissing('listing_documents', [
            'listing_id' => $listing->id,
            'type' => 'lead_disclosure',
        ]);
    }

    public function test_listing_processes_available_disclosures_api(): void
    {
        $res = $this->actingAs($this->agent)->getJson('/api/listing-processes/available-disclosures?state_code=MI');
        $res->assertStatus(200);

        $codes = collect($res->json())->pluck('code')->all();
        $this->assertContains('mi_residential_appliances', $codes);
        $this->assertContains('mi_vacant_land_disclosure', $codes);
    }
}
