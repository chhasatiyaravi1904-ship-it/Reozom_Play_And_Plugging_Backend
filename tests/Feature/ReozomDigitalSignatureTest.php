<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ListingDocumentController;
use App\Models\Listing;
use App\Models\ListingAnswer;
use App\Models\ListingDocument;
use App\Models\ListingEunoAgreement;
use App\Models\ListingProcess;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\EunoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;
use Tests\TestCase;

/**
 * Documents are signed only through EunoDoc, with Reozom's signer rules and $sN signature tags.
 */
class ReozomDigitalSignatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;
    protected Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        config(['euno.mock_mode' => true]);
        Storage::fake('local');

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
            'assigned_zips' => ['48126'],
            'required_disclosures' => ['lead_paint_disclosure'],
        ]);

        $pkg->update(['listing_process_id' => $process->id]);
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

    private function setOwnerAnswers(array $values): void
    {
        ListingAnswer::updateOrCreate(
            ['listing_id' => $this->listing->id, 'step_id' => 'step_property_type_address'],
            ['values' => $values]
        );
    }

    // --- Signers (Reozom's getAdditionalSigners rule) ---

    public function test_signer_one_follows_owner_type_then_additional_signers(): void
    {
        $this->setOwnerAnswers([
            'owner_type' => 'private',
            'owner_type_name' => 'John Owner',
            'owner_type_email' => 'john@example.com',
            'additional_signers' => "Mary Co-Owner, mary@example.com\nno email on this line\nJOHN@example.com\nBob <bob@example.com>",
        ]);

        $this->assertSame([
            ['name' => 'John Owner', 'email' => 'john@example.com'],
            ['name' => 'Mary Co-Owner', 'email' => 'mary@example.com'],
            ['name' => 'Bob', 'email' => 'bob@example.com'],
        ], $this->listing->eSignSigners());
    }

    public function test_trust_and_corporation_owner_types_use_their_own_fields(): void
    {
        $this->setOwnerAnswers([
            'owner_type' => 'trust',
            'owner_type_trust_name' => 'Doe Family Trust',
            'owner_type_trust_email' => 'trustee@example.com',
        ]);
        $this->assertSame('trustee@example.com', $this->listing->eSignSigners()[0]['email']);

        $this->setOwnerAnswers([
            'owner_type' => 'corporation',
            'owner_type_company_name' => 'Acme LLC',
            'owner_type_company_email' => 'signer@acme.test',
        ]);
        $this->assertSame(['name' => 'Acme LLC', 'email' => 'signer@acme.test'], $this->listing->eSignSigners()[0]);
    }

    public function test_signer_one_falls_back_to_the_account_holder(): void
    {
        $this->setOwnerAnswers(['owner_type' => 'private', 'owner_type_email' => 'not-an-email']);

        $this->assertSame(
            [['name' => 'Jane M. Doe', 'email' => 'jane.doe@reozom.com']],
            $this->listing->eSignSigners()
        );
    }

    // --- Documents carry Reozom's $sN signature tags, no fake seal ---

    public function test_generated_pdf_has_a_signature_tag_for_every_signer_and_no_fake_seal(): void
    {
        $this->setOwnerAnswers([
            'owner_type' => 'private',
            'owner_type_name' => 'John Owner',
            'owner_type_email' => 'john@example.com',
            'additional_signers' => 'Mary Co-Owner mary@example.com',
        ]);

        $controller = app(ListingDocumentController::class);
        $document = $controller->signableDocuments($this->listing)->firstWhere('type', 'lead_disclosure');
        $this->assertNotNull($document);

        $preview = $this->actingAs($this->seller)
            ->getJson("/api/listings/{$this->listing->id}/documents/lead_disclosure/preview")
            ->assertOk()
            ->json('data.html');
        $this->assertStringNotContainsString('REOZOM VERIFIED E-SIGN', $preview);
        $this->assertStringNotContainsString('Certificate:', $preview);
        $this->assertStringContainsString('john@example.com', $preview);
        $this->assertStringContainsString('mary@example.com', $preview);

        $pdfBytes = $controller->renderPdf($this->listing, $document);
        $pdf = (new PdfParser())->parseContent($pdfBytes);
        $this->assertStringContainsString('$s1', $pdf->getText());
        $this->assertStringContainsString('$s2', $pdf->getText());

        // The same lookup EunoService uses to place the fields finds both signers' tags.
        $searchText = new \ReflectionMethod(EunoService::class, 'searchText');
        $coordinates = $searchText->invoke(new EunoService(), $pdf, 0, [['email' => 'a'], ['email' => 'b']]);
        $this->assertStringContainsString("type:'signature', recipient:'0'", $coordinates);
        $this->assertStringContainsString("type:'signature', recipient:'1'", $coordinates);
    }

    // --- EunoDoc flow ---

    public function test_initiate_sends_all_signers_and_only_unsigned_documents(): void
    {
        $this->setOwnerAnswers([
            'owner_type' => 'private',
            'owner_type_name' => 'John Owner',
            'owner_type_email' => 'john@example.com',
            'additional_signers' => 'Mary Co-Owner mary@example.com',
        ]);

        $documents = app(ListingDocumentController::class)->signableDocuments($this->listing);
        $alreadySigned = $documents->first();
        $alreadySigned->update(['status' => 'signed', 'signed_at' => now()]);

        $response = $this->actingAs($this->seller)
            ->postJson("/api/listings/{$this->listing->id}/euno-sign/initiate")
            ->assertOk();

        $response->assertJsonPath('data.signers.0.email', 'john@example.com')
            ->assertJsonPath('data.signers.1.email', 'mary@example.com');

        $agreement = ListingEunoAgreement::firstOrFail();
        $sentIds = array_map('intval', array_keys($agreement->source_file_paths));
        $this->assertNotContains($alreadySigned->id, $sentIds);
        $this->assertCount($documents->count() - 1, $sentIds);

        foreach ($agreement->source_file_paths as $path) {
            Storage::disk('local')->assertExists($path);
            $this->assertStringStartsWith("listings/{$this->listing->id}/unsigned/", $path);
        }
    }

    public function test_initiate_fails_clearly_instead_of_faking_an_agreement_when_eunodoc_is_down(): void
    {
        config([
            'euno.mock_mode' => false,
            'euno.api_url' => 'http://127.0.0.1:9/api/v1',
            'euno.auth_token' => 'Bearer test',
            'euno.retries' => 0,
        ]);

        $this->actingAs($this->seller)
            ->postJson("/api/listings/{$this->listing->id}/euno-sign/initiate")
            ->assertStatus(502);

        $this->assertDatabaseCount('listing_euno_agreements', 0);
        $this->assertDatabaseMissing('listing_documents', ['listing_id' => $this->listing->id, 'status' => 'signed']);
    }

    public function test_initiate_fails_when_no_token_is_configured(): void
    {
        config(['euno.mock_mode' => false, 'euno.auth_token' => '']);

        $this->actingAs($this->seller)
            ->postJson("/api/listings/{$this->listing->id}/euno-sign/initiate")
            ->assertStatus(502);

        $this->assertDatabaseCount('listing_euno_agreements', 0);
    }

    public function test_completion_marks_only_agreement_documents_signed_and_serves_eunodoc_signed_pdf(): void
    {
        $this->actingAs($this->seller)
            ->postJson("/api/listings/{$this->listing->id}/euno-sign/initiate")
            ->assertOk();

        $deed = ListingDocument::create([
            'listing_id' => $this->listing->id,
            'user_id' => $this->seller->id,
            'type' => 'deed',
            'original_name' => 'deed.pdf',
            'path' => "listings/{$this->listing->id}/documents/deed.pdf",
            'mime_type' => 'application/pdf',
            'size' => 10,
            'status' => 'pending_review',
        ]);

        $this->actingAs($this->seller)
            ->getJson("/api/listings/{$this->listing->id}/euno-sign/status")
            ->assertOk()
            ->assertJsonPath('data.is_completed', true);

        $agreement = ListingEunoAgreement::firstOrFail();
        Storage::disk('local')->assertExists($agreement->signed_file_path);

        $signedDocs = ListingDocument::whereIn('id', array_keys($agreement->source_file_paths))->get();
        $this->assertNotEmpty($signedDocs);
        foreach ($signedDocs as $doc) {
            $this->assertSame('signed', $doc->status);
            $this->assertSame('eunodoc', $doc->signature_type);
            $this->assertSame($agreement->id, $doc->audit_trail['listing_euno_agreement_id']);
        }
        $this->assertSame('pending_review', $deed->fresh()->status);

        $type = $signedDocs->first()->type;
        $download = $this->actingAs($this->seller)
            ->get("/api/listings/{$this->listing->id}/documents/{$type}/download");
        $download->assertOk();
        $this->assertSame(Storage::disk('local')->get($agreement->signed_file_path), $download->getContent());
    }
}
