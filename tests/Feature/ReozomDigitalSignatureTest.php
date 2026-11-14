<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingDocument;
use App\Models\ListingProcess;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReozomDigitalSignatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;
    protected Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create([
            'role' => 'seller',
            'name' => 'Jane M. Doe',
            'email' => 'jane.doe@reozom.com',
        ]);

        $agent = User::factory()->create(['role' => 'agent']);

        $pkg = ServicePackage::create([
            'agent_id' => $agent->id,
            'name' => 'Full MLS Package',
            'price' => 295.00,
            'status' => 'active',
        ]);

        $process = ListingProcess::create([
            'name' => 'Michigan Seller Workflow',
            'status' => 'active',
            'service_package_id' => $pkg->id,
            'assigned_zips' => ['48126'],
            'required_disclosures' => ['lead_paint_disclosure'],
        ]);

        $this->listing = Listing::create([
            'user_id' => $this->seller->id,
            'service_package_id' => $pkg->id,
            'listing_process_id' => $process->id,
            'reference_code' => 'REO-SIG-1001',
            'status' => 'in_progress',
            'address' => '14520 Michigan Ave',
            'city' => 'Dearborn',
            'state' => 'MI',
            'zip' => '48126',
            'steps_completed' => 2,
            'steps_total' => 5,
        ]);
    }

    public function test_seller_can_sign_statutory_document_with_signature_image_and_audit_trail(): void
    {
        $signatureBase64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $response = $this->actingAs($this->seller)
            ->postJson("/api/listings/{$this->listing->id}/documents/lead_disclosure/sign", [
                'signer_name' => 'Jane M. Doe',
                'signature_image' => $signatureBase64,
                'signature_type' => 'drawn',
                'legal_consent' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'signed')
            ->assertJsonPath('data.signedBy', 'Jane M. Doe')
            ->assertJsonPath('data.signatureType', 'drawn');

        $doc = ListingDocument::where('listing_id', $this->listing->id)
            ->where('type', 'lead_disclosure')
            ->firstOrFail();

        $this->assertEquals('signed', $doc->status);
        $this->assertEquals('Jane M. Doe', $doc->signed_by);
        $this->assertEquals($signatureBase64, $doc->signature_image);
        $this->assertEquals('drawn', $doc->signature_type);
        $this->assertStringStartsWith('REO-SIGN-', $doc->verification_code);
        $this->assertNotNull($doc->audit_trail);
        $this->assertEquals('UETA & ESIGN Act Certified Electronic Signature', $doc->audit_trail['compliance']);
    }

    public function test_seller_can_sign_all_statutory_documents_packet(): void
    {
        $signatureBase64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $response = $this->actingAs($this->seller)
            ->postJson("/api/listings/{$this->listing->id}/documents/sign-all", [
                'signer_name' => 'Jane M. Doe',
                'signature_image' => $signatureBase64,
                'signature_type' => 'typed',
                'legal_consent' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'signedCount',
                    'documents',
                ],
            ]);

        $signedDocs = ListingDocument::where('listing_id', $this->listing->id)
            ->where('status', 'signed')
            ->get();

        $this->assertGreaterThanOrEqual(2, $signedDocs->count());

        foreach ($signedDocs as $doc) {
            $this->assertEquals('signed', $doc->status);
            $this->assertEquals('Jane M. Doe', $doc->signed_by);
            $this->assertStringStartsWith('REO-SIGN-', $doc->verification_code);
            $this->assertTrue($doc->audit_trail['packet_execution'] ?? false);
        }
    }

    public function test_signed_document_preview_and_download_renders_reozom_verification_seal(): void
    {
        $signatureBase64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $this->actingAs($this->seller)
            ->postJson("/api/listings/{$this->listing->id}/documents/lead_disclosure/sign", [
                'signer_name' => 'Jane M. Doe',
                'signature_image' => $signatureBase64,
                'signature_type' => 'drawn',
            ])
            ->assertOk();

        // Preview endpoint
        $preview = $this->actingAs($this->seller)
            ->getJson("/api/listings/{$this->listing->id}/documents/lead_disclosure/preview");

        $preview->assertOk();
        $html = $preview->json('data.html');
        $this->assertStringContainsString('REOZOM VERIFIED E-SIGN', $html);
        $this->assertStringContainsString('Jane M. Doe', $html);
        $this->assertStringContainsString('data:image/png;base64', $html);
        $this->assertStringContainsString('ESIGN', $html);

        // Download endpoint produces PDF
        $download = $this->actingAs($this->seller)
            ->get("/api/listings/{$this->listing->id}/documents/lead_disclosure/download");

        $download->assertOk();
        $this->assertEquals('application/pdf', $download->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $download->getContent());
    }
}

