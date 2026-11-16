<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Listing;
use App\Models\ListingDocument;
use App\Models\ListingProcess;
use App\Models\User;
use Database\Seeders\DocumentTemplateSeeder;
use Database\Seeders\FloridaListingProcessSeeder;
use Database\Seeders\ListingProcessDefinitions;
use Database\Seeders\ReozomStatutoryDisclosuresSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingDocumentGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReozomStatutoryDisclosuresSeeder::class);
        $this->seed(DocumentTemplateSeeder::class);
        User::factory()->create(['role' => UserRole::Agent, 'email' => 'agent@example.com']);
        $this->seed(FloridaListingProcessSeeder::class);

        $this->seller = User::factory()->create(['role' => UserRole::SellerBuyer]);
    }

    private function flListing(string $processName = 'Florida Residential Listing Process'): Listing
    {
        $process = ListingProcess::where('name', $processName)->firstOrFail();

        return Listing::factory()->create([
            'user_id' => $this->seller->id,
            'address' => '1 Ocean Drive',
            'city' => 'Miami',
            'state' => 'FL',
            'zip' => '33139',
            'listing_process_id' => $process->id,
            'workflow_snapshot' => $process->config,
            'steps_total' => count($process->config),
        ]);
    }

    private function saveStep(Listing $listing, string $stepId, array $values): void
    {
        $this->actingAs($this->seller)
            ->postJson("/api/listings/{$listing->id}/steps/{$stepId}", ['values' => $values])
            ->assertOk();
    }

    private function docCodes(Listing $listing): array
    {
        return $listing->documents()->whereNotNull('document_template_id')->orderBy('id')->pluck('type')->all();
    }

    public function test_single_family_gets_sf_hoa_and_lead_documents_but_no_condo_document(): void
    {
        $listing = $this->flListing();
        $this->saveStep($listing, 'step_property_type_address', ['property_sub_type' => '1']);
        $this->saveStep($listing, 'step_property_information', ['list_price' => 400000, 'year_built' => 1965]);
        $this->saveStep($listing, 'disclosure_fl_residential_single_family', ['field_11' => 'yes']);

        $codes = $this->docCodes($listing);

        foreach (['fl_res_sf_disclosure', 'fl_res_mold', 'fl_res_hoa', 'fl_res_lead', 'fl_res_radon',
            'fl_res_listing_agreement', 'fl_res_services_provided', 'fl_res_agency_disclosure'] as $expected) {
            $this->assertContains($expected, $codes);
        }
        $this->assertNotContains('fl_res_condo_disclosure', $codes);
    }

    public function test_condo_gets_condo_document_and_no_sf_hoa_or_lead_for_newer_homes(): void
    {
        $listing = $this->flListing();
        $this->saveStep($listing, 'step_property_type_address', ['property_sub_type' => '2']);
        $this->saveStep($listing, 'step_property_information', ['list_price' => 400000, 'year_built' => 2005]);

        $codes = $this->docCodes($listing);

        $this->assertContains('fl_res_condo_disclosure', $codes);
        $this->assertNotContains('fl_res_sf_disclosure', $codes);
        $this->assertNotContains('fl_res_hoa', $codes);
        $this->assertNotContains('fl_res_lead', $codes);
    }

    public function test_trust_non_owner_occupied_skips_disclosure_steps_and_documents(): void
    {
        $listing = $this->flListing();
        $this->saveStep($listing, 'step_property_type_address', [
            'property_sub_type' => '1',
            'owner_type' => 'trust',
            'owner_type_trust_type' => 'non_owner_occupied',
        ]);

        $codes = $this->docCodes($listing);
        foreach (['fl_res_sf_disclosure', 'fl_res_lead', 'fl_res_mold', 'fl_res_radon'] as $removed) {
            $this->assertNotContains($removed, $codes);
        }
        $this->assertContains('fl_res_listing_agreement', $codes);

        // Address step answered + every disclosure step skipped
        $disclosureSteps = count(ListingProcess::disclosureCodesFromConfig($listing->workflow_snapshot));
        $this->assertSame(1 + $disclosureSteps, $listing->fresh()->steps_completed);
    }

    public function test_agent_added_question_appears_in_the_disclosure_document(): void
    {
        $process = ListingProcess::where('name', 'Florida Residential Listing Process')->firstOrFail();
        $config = $process->config;
        $index = array_search('disclosure_fl_residential_single_family', array_column($config, 'id'));
        $config[$index]['sections'][0]['fields'][] = [
            'id' => 'agent_solar', 'name' => 'agent_solar', 'label' => 'Are the solar panels owned or leased?',
            'type' => 'select', 'options' => [['label' => 'Owned', 'value' => 'owned'], ['label' => 'Leased', 'value' => 'leased']],
        ];
        $process->update(['config' => $config]);

        $listing = $this->flListing();
        $this->saveStep($listing, 'step_property_type_address', ['property_sub_type' => '1']);
        $this->saveStep($listing, 'disclosure_fl_residential_single_family', ['agent_solar' => 'leased']);

        $content = $listing->documents()->where('type', 'fl_res_sf_disclosure')->value('content');
        $this->assertStringContainsString('Are the solar panels owned or leased?', $content);
        $this->assertStringContainsString('Leased', $content);
    }

    public function test_answers_are_escaped_in_documents(): void
    {
        $listing = $this->flListing();
        $this->saveStep($listing, 'step_property_type_address', ['property_sub_type' => '1']);
        $this->saveStep($listing, 'disclosure_fl_residential_single_family', ['field_5' => '<script>alert(1)</script>']);

        $content = $listing->documents()->where('type', 'fl_res_sf_disclosure')->value('content');
        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringContainsString('&lt;script&gt;', $content);
    }

    public function test_signed_documents_are_kept_when_answers_change(): void
    {
        $listing = $this->flListing();
        $this->saveStep($listing, 'step_property_type_address', ['property_sub_type' => '1']);
        $sf = ListingDocument::where('listing_id', $listing->id)->where('type', 'fl_res_sf_disclosure')->firstOrFail();
        $sf->update(['status' => 'signed', 'signed_at' => now(), 'signed_by' => 'Seller']);
        $signedContent = $sf->content;

        // Seller changes the property to a condo after signing
        $this->saveStep($listing, 'step_property_type_address', ['property_sub_type' => '2']);

        $sf->refresh();
        $this->assertSame('signed', $sf->status);
        $this->assertSame($signedContent, $sf->content);
        $this->assertContains('fl_res_condo_disclosure', $this->docCodes($listing));
    }

    public function test_process_document_list_limits_generated_documents(): void
    {
        ListingProcess::where('name', 'Florida Residential Listing Process')
            ->update(['documents' => json_encode(['fl_res_listing_agreement', 'fl_res_sf_disclosure'])]);

        $listing = $this->flListing();
        $this->saveStep($listing, 'step_property_type_address', ['property_sub_type' => '1']);

        $this->assertEqualsCanonicalizing(['fl_res_listing_agreement', 'fl_res_sf_disclosure'], $this->docCodes($listing));
    }

    public function test_process_document_list_is_saved_from_settings_and_validated(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $process = ListingProcess::where('name', 'Florida Residential Listing Process')->firstOrFail();

        $this->actingAs($admin)->putJson("/api/listing-processes/{$process->id}", [
            'documents' => ['fl_res_listing_agreement'],
        ])->assertOk();
        $this->assertSame(['fl_res_listing_agreement'], $process->refresh()->documents);

        $this->actingAs($admin)->putJson("/api/listing-processes/{$process->id}", [
            'documents' => ['not_a_template'],
        ])->assertStatus(422);

        $this->actingAs($admin)->putJson("/api/listing-processes/{$process->id}", ['documents' => null])->assertOk();
        $this->assertNull($process->refresh()->documents);

        $this->actingAs($admin)->getJson('/api/listing-processes/document-templates')
            ->assertOk()->assertJsonFragment(['code' => 'fl_res_hoa']);
    }

    public function test_vacant_land_gets_the_vacant_land_document_set(): void
    {
        $listing = $this->flListing('Florida Vacant Land Listing Process');
        $this->saveStep($listing, 'step_property_information', ['list_price' => 99000, 'acreage' => 5]);

        $this->assertEqualsCanonicalizing([
            'fl_land_listing_agreement', 'fl_land_real_estate_disclosure', 'fl_land_sellers_disclosure', 'fl_land_services_provided',
        ], $this->docCodes($listing));
    }

    public function test_documents_page_lists_generated_documents_and_preview_shows_content(): void
    {
        $listing = $this->flListing();
        $this->saveStep($listing, 'step_property_type_address', ['property_sub_type' => '1']);

        $items = collect($this->actingAs($this->seller)->getJson("/api/listings/{$listing->id}/documents")
            ->assertOk()->json('data'));

        $sf = $items->firstWhere('id', 'fl_res_sf_disclosure');
        $this->assertNotNull($sf);
        $this->assertSame("Seller's Property Disclosure – Single Family", $sf['label']);
        $this->assertTrue($sf['isSignable']);
        $this->assertTrue($sf['isGenerated']);
        $this->assertFalse($items->firstWhere('id', 'deed')['isGenerated']);
        $this->assertNotNull($items->firstWhere('id', 'deed'), 'Upload requirements are still listed');
        $this->assertNull($items->firstWhere('id', 'condo_disclosure'), 'Old hardcoded documents are not used');

        $html = $this->actingAs($this->seller)
            ->get("/api/listings/{$listing->id}/documents/fl_res_sf_disclosure/preview")
            ->assertOk()->getContent();
        $this->assertStringContainsString('Claims, Assessments', $html);
    }

    public function test_regenerate_resets_a_changed_signed_document(): void
    {
        $listing = $this->flListing();
        $this->saveStep($listing, 'step_property_type_address', ['property_sub_type' => '1']);
        $sf = ListingDocument::where('listing_id', $listing->id)->where('type', 'fl_res_sf_disclosure')->firstOrFail();
        $sf->update(['status' => 'signed', 'signed_at' => now()]);
        $this->saveStep($listing, 'disclosure_fl_residential_single_family', ['field_5' => 'yes']);

        $this->actingAs($this->seller)
            ->postJson("/api/listings/{$listing->id}/documents/fl_res_sf_disclosure/regenerate")
            ->assertOk();

        $sf->refresh();
        $this->assertSame('unsigned', $sf->status);
        $this->assertStringContainsString('Yes', $sf->content);
    }

    public function test_listings_without_disclosure_steps_keep_the_old_documents(): void
    {
        $listing = Listing::factory()->create([
            'user_id' => $this->seller->id,
            'state' => 'MI',
            'workflow_snapshot' => array_values(array_filter(
                ListingProcessDefinitions::getResidentialProcessConfig(),
                fn ($s) => ($s['type'] ?? null) !== 'disclosure'
            )),
        ]);

        $ids = collect($this->actingAs($this->seller)->getJson("/api/listings/{$listing->id}/documents")
            ->assertOk()->json('data'))->pluck('id');

        $this->assertContains('sellers_disclosure', $ids);
        $this->assertContains('listing_agreement', $ids);
    }
}
