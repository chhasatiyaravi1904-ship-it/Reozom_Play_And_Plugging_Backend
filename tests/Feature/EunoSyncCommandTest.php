<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingDocument;
use App\Models\ListingEunoAgreement;
use App\Models\ListingOffer;
use App\Models\OfferAddendum;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `euno:sync` picks up EunoDoc progress without anyone opening a page.
 */
class EunoSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $buyer;
    private Listing $listing;
    private ListingOffer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seller = User::factory()->create(['role' => 'seller_buyer', 'email' => 'seller.sync@example.com']);
        $this->buyer = User::factory()->create(['role' => 'seller_buyer', 'email' => 'buyer.sync@example.com']);
        $this->listing = Listing::create([
            'user_id' => $this->seller->id, 'status' => 'under_contract',
            'address' => '1 Main St', 'city' => 'Detroit', 'state' => 'MI', 'zip' => '48201',
        ]);
        $this->offer = ListingOffer::create([
            'listing_id' => $this->listing->id, 'buyer_id' => $this->buyer->id,
            'status' => ListingOffer::STATUS_ACCEPTED, 'offer_amount' => 1000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    private function agreement(string $eunoId, array $attributes = []): ListingEunoAgreement
    {
        return ListingEunoAgreement::create($attributes + [
            'listing_id' => $this->listing->id,
            'initiated_by_user_id' => $this->seller->id,
            'document_type' => 'disclosures_bundle',
            'document_name' => 'Reozom Documents',
            'agreement_id' => $eunoId,
            'status' => 'inprogress',
            'signers' => [['name' => 'Seller', 'email' => 'seller.sync@example.com', 'recipient_id' => 'r1']],
            'sent_at' => now(),
        ]);
    }

    /** EunoDoc reports these agreement ids as completed, everything else as still in progress. */
    private function eunoCompletes(array $completedIds, array $failingIds = []): void
    {
        $this->fakeEuno(function ($mock) use ($completedIds, $failingIds) {
            $mock->shouldReceive('getAgreementStatus')->andReturnUsing(function (string $id) use ($completedIds, $failingIds) {
                if (in_array($id, $failingIds, true)) {
                    throw new \RuntimeException('EunoDoc unreachable');
                }

                return in_array($id, $completedIds, true) ? 'completed' : 'inprogress';
            });
            $mock->shouldReceive('getSignedDocument')->andReturn('%PDF-1.4 signed');
            $mock->shouldReceive('getAuditReport')->andReturn('%PDF-1.4 audit');
        });
    }

    public function test_completes_finished_agreements_of_every_type_and_leaves_open_ones(): void
    {
        $document = ListingDocument::create([
            'listing_id' => $this->listing->id, 'user_id' => $this->seller->id, 'type' => 'sellers_disclosure',
            'original_name' => 'sd.pdf', 'path' => 'listings/1/documents/sd.pdf', 'mime_type' => 'application/pdf',
            'size' => 10, 'status' => 'pending',
        ]);
        $listingDocs = $this->agreement('euno-listing', ['source_file_paths' => [$document->id => 'listings/1/unsigned/sd.pdf']]);
        $purchase = $this->agreement('euno-pa', ['listing_offer_id' => $this->offer->id, 'document_type' => 'purchase_agreement']);
        $addendumAgreement = $this->agreement('euno-add', ['listing_offer_id' => $this->offer->id, 'document_type' => 'addendum']);
        $addendum = OfferAddendum::create([
            'listing_offer_id' => $this->offer->id, 'listing_id' => $this->listing->id, 'created_by_user_id' => $this->seller->id,
            'description' => 'New closing date', 'listing_euno_agreement_id' => $addendumAgreement->id,
        ]);
        $stillOpen = $this->agreement('euno-open', ['listing_offer_id' => $this->offer->id, 'document_type' => 'purchase_agreement']);

        $this->eunoCompletes(['euno-listing', 'euno-pa', 'euno-add']);

        $this->artisan('euno:sync')
            ->expectsOutput('EunoDoc sync: 3 completed, 1 not completed yet, 0 failed.')
            ->assertExitCode(0);

        // Listing documents: marked signed
        $this->assertSame('completed', $listingDocs->fresh()->status);
        $this->assertSame('signed', $document->fresh()->status);

        // Purchase agreement: signed copy and audit report stored
        $purchase->refresh();
        $this->assertSame('completed', $purchase->status);
        Storage::disk('local')->assertExists($purchase->signed_file_path);
        Storage::disk('local')->assertExists($purchase->audit_file_path);

        // Addendum: signed copy stored and shared with the listing and the buyer's library
        $this->assertSame("offers/{$this->offer->id}/signed/Addendum_{$addendum->id}.pdf", $addendumAgreement->fresh()->signed_file_path);
        $this->assertTrue(ListingDocument::where('listing_id', $this->listing->id)->where('type', 'addendum')->exists());
        $this->assertTrue(UserDocument::where('user_id', $this->buyer->id)->where('source', UserDocument::SOURCE_ADDENDUM)->exists());

        $this->assertSame('inprogress', $stillOpen->fresh()->status);
    }

    public function test_one_failing_agreement_does_not_stop_the_others(): void
    {
        $broken = $this->agreement('euno-broken', ['listing_offer_id' => $this->offer->id, 'document_type' => 'purchase_agreement']);
        $fine = $this->agreement('euno-fine', ['listing_offer_id' => $this->offer->id, 'document_type' => 'purchase_agreement']);

        $this->eunoCompletes(['euno-fine'], ['euno-broken']);

        $this->artisan('euno:sync')
            ->expectsOutput('EunoDoc sync: 1 completed, 0 not completed yet, 1 failed.')
            ->assertExitCode(1);

        $this->assertSame('inprogress', $broken->fresh()->status);
        $this->assertSame('completed', $fine->fresh()->status);
    }

    public function test_a_completed_agreement_is_not_marked_done_until_the_signed_copy_is_stored(): void
    {
        $purchase = $this->agreement('euno-pa', ['listing_offer_id' => $this->offer->id, 'document_type' => 'purchase_agreement']);
        $this->fakeEuno(function ($mock) {
            $mock->shouldReceive('getAgreementStatus')->andReturn('completed');
            $mock->shouldReceive('getSignedDocument')->andReturn(null);
        });

        $this->artisan('euno:sync')->assertExitCode(0);

        // Retried on the next run instead of being completed without a signed copy
        $this->assertSame('inprogress', $purchase->fresh()->status);
    }

    public function test_the_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('euno:sync')->assertExitCode(0);
    }
}
