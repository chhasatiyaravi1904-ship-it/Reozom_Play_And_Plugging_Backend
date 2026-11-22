<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Listing;
use App\Models\ListingDocument;
use App\Models\ListingEunoAgreement;
use App\Models\ListingOffer;
use App\Models\OfferAddendum;
use App\Models\ServicePackage;
use App\Models\User;
use App\Models\UserDocument;
use App\Notifications\DocumentsViewedNotification;
use App\Services\EunoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Remaining Reozom document features: one signed PDF per document, pending purchase agreement /
 * addendum signatures, the personal document library, the shared signed addendum, the
 * "documents viewed" email and the extra (fixed) forms.
 */
class ReozomDocumentGapsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['euno.mock_mode' => true]);
        Storage::fake('local');
    }

    private function pdfWithPages(int $pages): string
    {
        $html = implode('<div style="page-break-after: always;"></div>', array_map(fn ($i) => "<p>Page {$i}</p>", range(1, $pages)));

        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->output();
    }

    private function doc(Listing $listing, string $type, string $status = 'unsigned', ?User $owner = null): ListingDocument
    {
        return $listing->documents()->create([
            'user_id' => ($owner ?? $listing->user)->id, 'type' => $type, 'original_name' => "{$type}.pdf",
            'path' => "listings/{$listing->id}/{$type}.pdf", 'mime_type' => 'application/pdf', 'size' => 10, 'status' => $status,
        ]);
    }

    // --- 1. one signed PDF per document ---

    public function test_signed_packet_is_split_into_one_pdf_per_document(): void
    {
        Process::fake();
        $seller = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $listing = Listing::factory()->create(['user_id' => $seller->id]);
        $first = $this->doc($listing, 'sellers_disclosure');
        $second = $this->doc($listing, 'lead_disclosure');
        ListingEunoAgreement::create([
            'listing_id' => $listing->id, 'agreement_id' => 'agr_split', 'status' => 'inprogress',
            'signers' => [['name' => 'Owner', 'email' => $seller->email]],
            'source_file_paths' => [$first->id => 'a.pdf', $second->id => 'b.pdf'],
            // 1 page + 2 pages; EunoDoc appends an audit page (4 in total)
            'document_pages' => [$first->id => 1, $second->id => 2],
        ]);
        $signed = $this->pdfWithPages(4);
        $this->fakeEuno(function ($mock) use ($signed) {
            $mock->shouldReceive('getAgreementStatus')->andReturn('completed');
            $mock->shouldReceive('getSignedDocument')->andReturn($signed);
            $mock->shouldReceive('getAuditReport')->andReturn(null);
        });

        $this->actingAs($seller)->getJson("/api/listings/{$listing->id}/euno-sign/status")->assertOk();

        Process::assertRan(fn ($process) => in_array('1-1', $process->command, true));
        Process::assertRan(fn ($process) => in_array('2-3', $process->command, true));
        $this->assertSame('signed', $first->fresh()->status);
    }

    public function test_packet_with_too_few_pages_is_not_split(): void
    {
        Process::fake();
        $seller = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $listing = Listing::factory()->create(['user_id' => $seller->id]);
        $doc = $this->doc($listing, 'sellers_disclosure');
        ListingEunoAgreement::create([
            'listing_id' => $listing->id, 'agreement_id' => 'agr_short', 'status' => 'inprogress',
            'signers' => [['name' => 'Owner', 'email' => $seller->email]],
            'source_file_paths' => [$doc->id => 'a.pdf'],
            'document_pages' => [$doc->id => 5],
        ]);
        $signed = $this->pdfWithPages(2);
        $this->fakeEuno(function ($mock) use ($signed) {
            $mock->shouldReceive('getAgreementStatus')->andReturn('completed');
            $mock->shouldReceive('getSignedDocument')->andReturn($signed);
            $mock->shouldReceive('getAuditReport')->andReturn(null);
        });

        $this->actingAs($seller)->getJson("/api/listings/{$listing->id}/euno-sign/status")->assertOk();

        Process::assertNothingRan();
        $this->assertSame('signed', $doc->fresh()->status, 'Still signed; the whole packet is served');
        $this->assertNull($doc->fresh()->audit_trail['signed_file_path'] ?? null);
    }

    // --- 2. pending purchase agreement signatures ---

    public function test_offer_signers_who_have_not_signed_see_the_purchase_agreement_in_pending_signatures(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SellerBuyer, 'email' => 'owner@example.com']);
        $buyer = User::factory()->create(['role' => UserRole::SellerBuyer, 'email' => 'buyer@example.com']);
        $listing = Listing::factory()->create(['user_id' => $seller->id]);
        $offer = ListingOffer::create(['listing_id' => $listing->id, 'buyer_id' => $buyer->id, 'offer_amount' => 1, 'closing_date' => now()->addDays(30), 'status' => 'accepted']);
        ListingEunoAgreement::create([
            'listing_id' => $listing->id, 'listing_offer_id' => $offer->id, 'document_type' => 'purchase_agreement',
            'agreement_id' => 'agr_pa', 'status' => 'inprogress',
            'signers' => [
                ['name' => 'Buyer', 'email' => 'buyer@example.com', 'role' => 'buyer', 'recipient_id' => 'rec_b'],
                ['name' => 'Owner', 'email' => 'owner@example.com', 'role' => 'seller', 'recipient_id' => 'rec_s'],
            ],
        ]);
        $this->fakeEuno(fn ($mock) => $mock->shouldReceive('getDocumentUrl')->andReturn(['recipients' => [
            ['_id' => 'rec_b', 'email' => 'buyer@example.com', 'status' => 'completed'],
            ['_id' => 'rec_s', 'email' => 'owner@example.com', 'status' => 'pending'],
        ]]));

        $this->actingAs($seller)->getJson('/api/me/pending-signatures')->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.items.0.kind', 'purchase_agreement')
            ->assertJsonPath('data.items.0.offer_id', $offer->id)
            ->assertJsonPath('data.items.0.signing_url', fn ($url) => str_ends_with($url, '/rec_s'));
        $this->actingAs($buyer)->getJson('/api/me/pending-signatures')->assertOk()->assertJsonPath('data.count', 0);
    }

    // --- 3. personal document library ---

    public function test_user_manages_their_own_documents(): void
    {
        Mail::fake();
        $user = User::factory()->create(['role' => UserRole::SellerBuyer]);

        $id = $this->actingAs($user)->post('/api/me/documents', [
            'name' => 'Pre-approval letter',
            'file' => UploadedFile::fake()->create('letter.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $document = UserDocument::findOrFail($id);
        Storage::disk('local')->assertExists($document->path);

        $this->actingAs($user)->getJson('/api/me/documents')->assertOk()->assertJsonPath('data.0.name', 'Pre-approval letter');
        $this->actingAs($user)->putJson("/api/me/documents/{$id}", ['name' => 'Bank letter'])->assertOk()->assertJsonPath('data.name', 'Bank letter');
        $this->actingAs($user)->get("/api/me/documents/{$id}/download")->assertOk();
        $this->actingAs($user)->postJson("/api/me/documents/{$id}/send-email", ['emails' => ['lender@example.com']])->assertOk();

        $this->actingAs($user)->deleteJson("/api/me/documents/{$id}")->assertOk();
        Storage::disk('local')->assertMissing($document->path);
        $this->assertDatabaseMissing('user_documents', ['id' => $id]);
    }

    public function test_users_cannot_reach_each_others_documents_and_bad_files_are_refused(): void
    {
        $owner = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $other = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $document = UserDocument::create([
            'user_id' => $owner->id, 'name' => 'Private', 'original_name' => 'p.pdf', 'path' => 'users/x/p.pdf',
            'mime_type' => 'application/pdf', 'size' => 1,
        ]);

        $this->actingAs($other)->getJson('/api/me/documents')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other)->get("/api/me/documents/{$document->id}/download")->assertNotFound();
        $this->actingAs($other)->putJson("/api/me/documents/{$document->id}", ['name' => 'Mine'])->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/me/documents/{$document->id}")->assertNotFound();
        $this->assertDatabaseHas('user_documents', ['id' => $document->id, 'name' => 'Private']);

        $this->actingAs($owner)->post('/api/me/documents', [
            'file' => UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    // --- 4. signed addendum shared with seller and buyer ---

    public function test_signed_addendum_is_shared_to_the_listing_and_the_buyer_library_once(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $buyer = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $listing = Listing::factory()->create(['user_id' => $seller->id]);
        $offer = ListingOffer::create(['listing_id' => $listing->id, 'buyer_id' => $buyer->id, 'offer_amount' => 1, 'closing_date' => now()->addDays(30), 'status' => 'accepted']);
        $agreement = ListingEunoAgreement::create([
            'listing_id' => $listing->id, 'listing_offer_id' => $offer->id, 'document_type' => 'addendum',
            'agreement_id' => 'agr_add', 'status' => 'inprogress', 'signers' => [],
        ]);
        $addendum = OfferAddendum::create([
            'listing_offer_id' => $offer->id, 'listing_id' => $listing->id, 'created_by_user_id' => $seller->id,
            'description' => 'New closing date', 'listing_euno_agreement_id' => $agreement->id,
        ]);
        $this->fakeEuno(function ($mock) {
            $mock->shouldReceive('getAgreementStatus')->andReturn('completed');
            $mock->shouldReceive('getSignedDocument')->andReturn('%PDF-1.4 signed addendum');
        });

        $this->actingAs($seller)->getJson("/api/offers/{$offer->id}/addendums")->assertOk()->assertJsonPath('data.0.status', 'completed');
        $addendum->shareSignedCopy($agreement->fresh()->signed_file_path); // second call changes nothing

        $this->assertSame(1, ListingDocument::where('listing_id', $listing->id)->where('type', 'addendum')->count());
        $buyerCopies = UserDocument::where('user_id', $buyer->id)->where('source', UserDocument::SOURCE_ADDENDUM)->get();
        $this->assertCount(1, $buyerCopies);
        Storage::disk('local')->assertExists($buyerCopies->first()->path);
    }

    public function test_canceling_an_addendum_cancels_signing_and_removes_the_shared_copies(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $buyer = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $stranger = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $listing = Listing::factory()->create(['user_id' => $seller->id]);
        $offer = ListingOffer::create(['listing_id' => $listing->id, 'buyer_id' => $buyer->id, 'offer_amount' => 1, 'closing_date' => now()->addDays(30), 'status' => 'accepted']);
        $agreement = ListingEunoAgreement::create([
            'listing_id' => $listing->id, 'listing_offer_id' => $offer->id, 'document_type' => 'addendum',
            'agreement_id' => 'mock_add_cancel', 'status' => 'inprogress', 'signers' => [],
        ]);
        $addendum = OfferAddendum::create([
            'listing_offer_id' => $offer->id, 'listing_id' => $listing->id, 'created_by_user_id' => $seller->id,
            'description' => 'Change', 'listing_euno_agreement_id' => $agreement->id,
        ]);
        Storage::disk('local')->put('tmp/signed.pdf', '%PDF-1.4 signed');
        $addendum->shareSignedCopy('tmp/signed.pdf');
        $buyerCopy = UserDocument::where('user_id', $buyer->id)->firstOrFail();

        $this->actingAs($stranger)->deleteJson("/api/offers/{$offer->id}/addendums/{$addendum->id}")->assertForbidden();
        $this->actingAs($buyer)->deleteJson("/api/offers/{$offer->id}/addendums/{$addendum->id}")->assertOk();

        $this->assertDatabaseMissing('offer_addendums', ['id' => $addendum->id]);
        $this->assertSame('canceled', $agreement->fresh()->status);
        $this->assertSame(0, ListingDocument::where('listing_id', $listing->id)->where('type', 'addendum')->count());
        $this->assertDatabaseMissing('user_documents', ['id' => $buyerCopy->id]);
        Storage::disk('local')->assertMissing($buyerCopy->path);
    }

    // --- 5. documents viewed email ---

    public function test_seller_is_emailed_when_someone_else_views_an_uploaded_document(): void
    {
        Notification::fake();
        $seller = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $agent = User::factory()->create(['role' => UserRole::Agent]);
        $package = ServicePackage::create(['agent_id' => $agent->id, 'name' => 'MLS', 'price' => null, 'is_active' => true]);
        $listing = Listing::factory()->create(['user_id' => $seller->id, 'service_package_id' => $package->id]);
        $deed = $this->doc($listing, 'deed', 'uploaded');
        Storage::disk('local')->put($deed->path, '%PDF-1.4 deed');

        // The owner viewing their own file sends nothing
        $this->actingAs($seller)->get("/api/listings/{$listing->id}/documents/{$deed->id}/view")->assertOk();
        Notification::assertNothingSent();

        $this->actingAs($agent)->get("/api/listings/{$listing->id}/documents/{$deed->id}/view")->assertOk();
        $this->actingAs($agent)->get("/api/listings/{$listing->id}/documents/{$deed->id}/download")->assertOk();

        Notification::assertSentToTimes($seller, DocumentsViewedNotification::class, 1);
    }

    // --- 6. extra (fixed) forms ---

    public function test_extra_forms_cannot_be_deleted_or_uploaded_by_hand(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $listing = Listing::factory()->create(['user_id' => $seller->id]);
        $this->doc($listing, 'condo_governance_form', 'uploaded');

        $this->actingAs($seller)->deleteJson("/api/listings/{$listing->id}/documents/condo_governance_form")->assertStatus(422);
        $this->assertDatabaseHas('listing_documents', ['listing_id' => $listing->id, 'type' => 'condo_governance_form']);

        $this->actingAs($seller)->post("/api/listings/{$listing->id}/documents", [
            'document_id' => 'condo_governance_form',
            'file' => UploadedFile::fake()->create('form.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }
}
