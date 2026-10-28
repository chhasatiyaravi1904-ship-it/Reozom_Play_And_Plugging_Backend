<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ListingDisclosureAndDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;
    protected Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seller = User::factory()->create(['role' => 'seller']);

        $this->listing = Listing::create([
            'user_id' => $this->seller->id,
            'status' => 'in_progress',
            'address' => '456 Grand River Ave',
            'city' => 'East Lansing',
            'state' => 'MI',
            'zip' => '48823',
        ]);
    }

    public function test_seller_can_fetch_statutory_disclosure_schema(): void
    {
        $response = $this->actingAs($this->seller)
            ->getJson("/api/listings/{$this->listing->id}/disclosures");

        $response->assertOk();
        $response->assertJsonPath('data.listingId', $this->listing->id);
        $response->assertJsonStructure([
            'data' => [
                'listingId',
                'steps',
                'answers',
            ],
        ]);
        $this->assertCount(4, $response->json('data.steps'));
    }

    public function test_seller_can_save_disclosures_and_auto_generate_statutory_documents(): void
    {
        $answers = [
            'property_conditions' => [
                'basement_water_id' => 'no',
                'roof_leaks_id' => 'no',
                'roof_age' => '5 years',
                'insulation' => 'Fiberglass',
                'installed_uffi_id' => 'no',
                'plumbing_system' => 'no',
                'electrical_system' => 'no',
                'heating_system' => 'Natural Gas Forced Air',
                'well_septic_id' => 'no_municipal',
                'environmental_problems' => 'no',
                'structural_settling' => 'no',
            ],
            'lead_paint_disclosure' => [
                'built_before_1978' => 'no',
                'lead_knowledge_id' => 'no_knowledge',
                'lead_reports_id' => 'no_reports',
            ],
            'appliances_fixtures' => [
                'range_oven' => 'included_working',
                'refrigerator' => 'included_working',
                'dishwasher' => 'included_working',
                'washer_dryer' => 'included_working',
                'water_heater' => 'owned_working',
            ],
            'ownership_additional' => [
                'date_owned' => '2019',
                'common_areas_id' => 'no',
                'litigation_id' => 'no',
            ],
        ];

        $response = $this->actingAs($this->seller)
            ->postJson("/api/listings/{$this->listing->id}/disclosures", [
                'answers' => $answers,
            ]);

        $response->assertOk();

        // Verify statutory documents were generated
        $this->assertDatabaseHas('listing_documents', [
            'listing_id' => $this->listing->id,
            'type' => 'sellers_disclosure',
            'status' => 'unsigned',
        ]);

        $this->assertDatabaseHas('listing_documents', [
            'listing_id' => $this->listing->id,
            'type' => 'lead_disclosure',
            'status' => 'unsigned',
        ]);

        $this->assertDatabaseHas('listing_documents', [
            'listing_id' => $this->listing->id,
            'type' => 'listing_agreement',
            'status' => 'unsigned',
        ]);
    }

    public function test_seller_can_e_sign_statutory_document(): void
    {
        $document = ListingDocument::create([
            'listing_id' => $this->listing->id,
            'user_id' => $this->seller->id,
            'type' => 'sellers_disclosure',
            'original_name' => "Sellers_Disclosure_Statement.pdf",
            'path' => "listings/{$this->listing->id}/statutory/sellers_disclosure.html",
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'unsigned',
        ]);

        $response = $this->actingAs($this->seller)
            ->postJson("/api/listings/{$this->listing->id}/documents/{$document->id}/sign", [
                'signer_name' => 'Jane Seller',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'signed');
        $response->assertJsonPath('data.signedBy', 'Jane Seller');

        $document->refresh();
        $this->assertEquals('signed', $document->status);
        $this->assertEquals('Jane Seller', $document->signed_by);
        $this->assertNotNull($document->signed_at);
    }

    public function test_seller_can_preview_statutory_document_html(): void
    {
        $document = ListingDocument::create([
            'listing_id' => $this->listing->id,
            'user_id' => $this->seller->id,
            'type' => 'sellers_disclosure',
            'original_name' => "Sellers_Disclosure_Statement.pdf",
            'path' => "listings/{$this->listing->id}/statutory/sellers_disclosure.html",
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'unsigned',
        ]);

        $response = $this->actingAs($this->seller)
            ->getJson("/api/listings/{$this->listing->id}/documents/{$document->id}/preview");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'type',
                'html',
            ],
        ]);
        $this->assertStringContainsString('456 Grand River Ave', $response->json('data.html'));
    }

    public function test_seller_can_upload_supplementary_reozom_document(): void
    {
        $file = UploadedFile::fake()->create('property_survey.pdf', 300, 'application/pdf');

        $response = $this->actingAs($this->seller)
            ->postJson("/api/listings/{$this->listing->id}/documents", [
                'document_id' => 'survey',
                'custom_name' => 'Boundary Survey 2024',
                'file' => $file,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('listing_documents', [
            'listing_id' => $this->listing->id,
            'type' => 'survey',
            'original_name' => 'Boundary Survey 2024.pdf',
            'status' => 'pending_review',
        ]);
    }
}
