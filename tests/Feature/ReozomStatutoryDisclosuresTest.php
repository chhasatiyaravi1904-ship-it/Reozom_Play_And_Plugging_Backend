<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingAnswer;
use App\Models\PropertyDisclosure;
use App\Models\User;
use App\Services\DisclosureService;
use Database\Seeders\ReozomStatutoryDisclosuresSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReozomStatutoryDisclosuresTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $seller;
    protected DisclosureService $disclosureService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->seller = User::factory()->create(['role' => 'seller']);
        $this->disclosureService = app(DisclosureService::class);

        // Run the statutory seeder
        $this->seed(ReozomStatutoryDisclosuresSeeder::class);
    }

    public function test_seeder_populates_all_reozom_statutory_disclosures(): void
    {
        $expectedCodes = [
            'mi_residential_appliances',
            'mi_residential_conditions',
            'lead_paint_disclosure',
            'mi_vacant_land_disclosure',
            'fl_residential_single_family',
            'fl_residential_condo',
            'fl_vacant_land_disclosure',
        ];

        foreach ($expectedCodes as $code) {
            $this->assertDatabaseHas('property_disclosures', [
                'code' => $code,
                'is_active' => true,
            ]);
        }

        $this->assertGreaterThanOrEqual(7, PropertyDisclosure::count());
    }

    public function test_michigan_residential_listing_resolves_statutory_mcl_disclosures(): void
    {
        $listing = Listing::create([
            'user_id' => $this->seller->id,
            'address' => '14520 Michigan Ave',
            'city' => 'Dearborn',
            'state' => 'MI',
            'zip' => '48126',
            'status' => 'in_progress',
        ]);

        ListingAnswer::create([
            'listing_id' => $listing->id,
            'step_id' => 'system_public_info',
            'values' => [
                'property_type' => 'single_family',
                'listing_price' => 450000,
            ],
        ]);

        $schemaSteps = $this->disclosureService->getDisclosureSchema($listing);
        $stepIds = array_column($schemaSteps, 'id');

        $this->assertContains('mi_residential_appliances', $stepIds);
        $this->assertContains('mi_residential_conditions', $stepIds);
        $this->assertContains('lead_paint_disclosure', $stepIds);
        $this->assertNotContains('mi_vacant_land_disclosure', $stepIds);
        $this->assertNotContains('fl_residential_single_family', $stepIds);

        // Inspect Michigan Appliances Step has 35+ items
        $appliancesStep = collect($schemaSteps)->firstWhere('id', 'mi_residential_appliances');
        $this->assertNotNull($appliancesStep);
        $fieldsCount = collect($appliancesStep['sections'])->flatMap(fn($s) => $s['fields'])->count();
        $this->assertGreaterThanOrEqual(30, $fieldsCount);

        // Inspect Michigan Conditions Step has basement water, roof, well, and environmental
        $conditionsStep = collect($schemaSteps)->firstWhere('id', 'mi_residential_conditions');
        $this->assertNotNull($conditionsStep);
        $allFieldIds = collect($conditionsStep['sections'])->flatMap(fn($s) => $s['fields'])->pluck('id')->all();
        $this->assertContains('basement_water_id', $allFieldIds);
        $this->assertContains('roof_leaks_id', $allFieldIds);
        $this->assertContains('installed_uffi_id', $allFieldIds);
        $this->assertContains('environmental_problems_id', $allFieldIds);
        $this->assertContains('date_owned', $allFieldIds);
    }

    public function test_michigan_vacant_land_listing_resolves_exact_reozom_q1_to_q11(): void
    {
        $listing = Listing::create([
            'user_id' => $this->seller->id,
            'address' => 'Lot 14 Greenfield Rd',
            'city' => 'Ann Arbor',
            'state' => 'MI',
            'zip' => '48105',
            'status' => 'in_progress',
        ]);

        ListingAnswer::create([
            'listing_id' => $listing->id,
            'step_id' => 'system_public_info',
            'values' => [
                'property_type' => 'vacant_land',
                'listing_price' => 195000,
            ],
        ]);

        $schemaSteps = $this->disclosureService->getDisclosureSchema($listing);
        $stepIds = array_column($schemaSteps, 'id');

        $this->assertContains('mi_vacant_land_disclosure', $stepIds);
        $this->assertNotContains('mi_residential_appliances', $stepIds);
        $this->assertNotContains('mi_residential_conditions', $stepIds);

        $vlStep = collect($schemaSteps)->firstWhere('id', 'mi_vacant_land_disclosure');
        $fieldIds = collect($vlStep['sections'])->flatMap(fn($s) => $s['fields'])->pluck('id')->all();

        // Exact Reozom Vacant Land questions: q1 - q11, explanations, date_owned
        $this->assertContains('q1', $fieldIds);
        $this->assertContains('q1a', $fieldIds);
        $this->assertContains('q2', $fieldIds);
        $this->assertContains('q3', $fieldIds);
        $this->assertContains('q4', $fieldIds);
        $this->assertContains('q5', $fieldIds);
        $this->assertContains('q6', $fieldIds);
        $this->assertContains('q7', $fieldIds);
        $this->assertContains('q8', $fieldIds);
        $this->assertContains('q9', $fieldIds);
        $this->assertContains('q10', $fieldIds);
        $this->assertContains('q11', $fieldIds);
        $this->assertContains('explanations', $fieldIds);
        $this->assertContains('date_owned', $fieldIds);
    }

    public function test_florida_residential_and_condo_listings_resolve_correct_statutory_disclosures(): void
    {
        // 1. Florida Single Family
        $flSf = Listing::create([
            'user_id' => $this->seller->id,
            'address' => '742 Ocean Drive',
            'city' => 'Miami Beach',
            'state' => 'FL',
            'zip' => '33139',
            'status' => 'in_progress',
        ]);
        ListingAnswer::create([
            'listing_id' => $flSf->id,
            'step_id' => 'system_public_info',
            'values' => ['property_type' => 'single_family'],
        ]);

        $sfSteps = array_column($this->disclosureService->getDisclosureSchema($flSf), 'id');
        $this->assertContains('fl_residential_single_family', $sfSteps);
        $this->assertContains('lead_paint_disclosure', $sfSteps);
        $this->assertNotContains('mi_residential_conditions', $sfSteps);

        // 2. Florida Condo
        $flCondo = Listing::create([
            'user_id' => $this->seller->id,
            'address' => '100 Brickell Ave Unit 1402',
            'city' => 'Miami',
            'state' => 'FL',
            'zip' => '33131',
            'status' => 'in_progress',
        ]);
        ListingAnswer::create([
            'listing_id' => $flCondo->id,
            'step_id' => 'system_public_info',
            'values' => ['property_type' => 'condo'],
        ]);

        $condoSteps = array_column($this->disclosureService->getDisclosureSchema($flCondo), 'id');
        $this->assertContains('fl_residential_condo', $condoSteps);
        $this->assertContains('lead_paint_disclosure', $condoSteps);
        $this->assertNotContains('fl_vacant_land_disclosure', $condoSteps);
    }

    public function test_admin_can_manage_statutory_disclosures_via_api(): void
    {
        // Fetch all disclosures as Admin
        $listRes = $this->actingAs($this->admin)->getJson('/api/admin/disclosures?state_code=MI');
        $listRes->assertStatus(200);
        $miDisclosures = $listRes->json('data');
        $this->assertNotEmpty($miDisclosures);

        // Filter by Florida
        $flRes = $this->actingAs($this->admin)->getJson('/api/admin/disclosures?state_code=FL');
        $flRes->assertStatus(200);
        $this->assertNotEmpty($flRes->json('data'));

        // Toggle disclosure active status
        $miAppliances = PropertyDisclosure::where('code', 'mi_residential_appliances')->first();
        $this->actingAs($this->admin)->postJson("/api/admin/disclosures/{$miAppliances->id}/toggle")->assertStatus(200);
        $this->assertFalse($miAppliances->fresh()->is_active);

        // Re-enable
        $this->actingAs($this->admin)->postJson("/api/admin/disclosures/{$miAppliances->id}/toggle")->assertStatus(200);
        $this->assertTrue($miAppliances->fresh()->is_active);
    }
}

