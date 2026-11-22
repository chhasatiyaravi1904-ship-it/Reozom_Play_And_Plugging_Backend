<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingEunoAgreement;
use App\Models\ListingOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EunoSignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No real EunoDoc in tests; failures no longer silently fall back to a mock agreement.
        config(['euno.mock_mode' => true]);
    }

    public function test_seller_can_initiate_euno_signing_for_listing(): void
    {
        $seller = User::factory()->create(['role' => 'seller']);
        $listing = Listing::factory()->create([
            'user_id' => $seller->id,
            'reference_code' => 'RZ-TEST01',
        ]);

        $response = $this->actingAs($seller)->postJson("/api/listings/{$listing->id}/euno-sign/initiate");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'agreement_id',
                    'euno_agreement_id',
                    'signing_url',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('listing_euno_agreements', [
            'listing_id' => $listing->id,
            'initiated_by_user_id' => $seller->id,
            'status' => 'inprogress',
        ]);
    }

    public function test_can_check_listing_euno_signing_status(): void
    {
        $seller = User::factory()->create(['role' => 'seller']);
        $listing = Listing::factory()->create(['user_id' => $seller->id]);

        $agreement = ListingEunoAgreement::create([
            'listing_id' => $listing->id,
            'initiated_by_user_id' => $seller->id,
            'agreement_id' => 'mock_agree_123',
            'signing_url' => 'https://eunodoc.reozom.com/esign/mock_agree_123/rec_1',
            'status' => 'inprogress',
        ]);

        $response = $this->actingAs($seller)->getJson("/api/listings/{$listing->id}/euno-sign/status");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.has_agreement', true);
    }

    public function test_buyer_can_initiate_offer_euno_signing(): void
    {
        $seller = User::factory()->create(['role' => 'seller']);
        $buyer = User::factory()->create(['role' => 'buyer']);
        $listing = Listing::factory()->create(['user_id' => $seller->id]);
        $offer = ListingOffer::create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'offer_amount' => 450000,
            'closing_date' => now()->addDays(30),
            // Purchase agreements are signed only after the seller accepts
            'status' => 'accepted',
        ]);

        $response = $this->actingAs($buyer)->postJson("/api/offers/{$offer->id}/euno-sign/initiate");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'agreement_id',
                    'euno_agreement_id',
                    'signing_url',
                ],
            ]);

        $this->assertDatabaseHas('listing_euno_agreements', [
            'listing_offer_id' => $offer->id,
            'status' => 'inprogress',
        ]);
    }

    // --- Signing order (original Reozom) ---

    public function test_agent_listing_has_no_listing_documents_to_sign(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $listing = Listing::factory()->create(['user_id' => $agent->id]);

        $doc = fn (string $type, string $status) => $listing->documents()->create([
            'user_id' => $agent->id, 'type' => $type, 'original_name' => "{$type}.pdf",
            'path' => "listings/{$listing->id}/{$type}.pdf", 'mime_type' => 'application/pdf', 'size' => 10, 'status' => $status,
        ]);
        $unsigned = $doc('listing_agreement', 'unsigned');
        $signed = $doc('agency_disclosure', 'signed');
        $uploaded = $doc('deed', 'pending');

        $ids = collect($this->actingAs($agent)->getJson("/api/listings/{$listing->id}/documents")->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertNotContains('listing_agreement', $ids);
        $this->assertDatabaseMissing('listing_documents', ['id' => $unsigned->id]);
        $this->assertDatabaseHas('listing_documents', ['id' => $signed->id]);
        $this->assertDatabaseHas('listing_documents', ['id' => $uploaded->id]);
        $this->assertSame([], $listing->fresh()->eSignSigners());

        $this->actingAs($agent)->postJson("/api/listings/{$listing->id}/euno-sign/initiate")->assertStatus(422);
        $this->assertDatabaseCount('listing_euno_agreements', 0);
    }

    public function test_seller_listing_signers_are_owner_first_then_additional_signers(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'email' => 'account@example.com']);
        $listing = Listing::factory()->create(['user_id' => $seller->id]);
        $listing->answers()->create(['step_id' => 'step_property_type_address', 'values' => [
            'owner_type' => 'private',
            'owner_type_name' => 'Olivia Owner',
            'owner_type_email' => 'owner@example.com',
            'additional_signers' => "Sam Second sam@example.com\nTina Third tina@example.com\nDup owner@example.com",
        ]]);

        $this->assertSame(
            ['owner@example.com', 'sam@example.com', 'tina@example.com'],
            array_column($listing->fresh()->eSignSigners(), 'email')
        );
    }

    public function test_only_the_next_signer_gets_a_signing_link(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'email' => 'owner@example.com']);
        $extra = User::factory()->create(['role' => 'seller', 'email' => 'sam@example.com']);
        $listing = Listing::factory()->create(['user_id' => $seller->id]);
        $agreement = ListingEunoAgreement::create([
            'listing_id' => $listing->id,
            'initiated_by_user_id' => $seller->id,
            'agreement_id' => 'agr_order_1',
            'signing_url' => 'https://eunodoc.test/esign/agr_order_1/rec_owner',
            'status' => 'inprogress',
            'signers' => [
                ['name' => 'Olivia Owner', 'email' => 'owner@example.com', 'recipient_id' => 'rec_owner'],
                ['name' => 'Sam Second', 'email' => 'sam@example.com', 'recipient_id' => 'rec_sam'],
            ],
        ]);

        $ownerStatus = 'pending';
        $this->fakeEuno(function ($mock) use (&$ownerStatus) {
            $mock->shouldReceive('getAgreementStatus')->andReturn('inprogress');
            // A closure with a reference (not fn) so the status change below is seen
            $mock->shouldReceive('getDocumentUrl')->andReturnUsing(function () use (&$ownerStatus) {
                return ['_id' => 'agr_order_1', 'recipients' => [
                    ['_id' => 'rec_owner', 'email' => 'owner@example.com', 'status' => $ownerStatus],
                    ['_id' => 'rec_sam', 'email' => 'sam@example.com', 'status' => 'pending'],
                ]];
            });
        });

        // Owner has not signed: only the owner gets a link
        $this->actingAs($seller)->getJson('/api/me/pending-signatures')->assertOk()->assertJsonPath('data.count', 1);
        $this->actingAs($extra)->getJson('/api/me/pending-signatures')->assertOk()->assertJsonPath('data.count', 0);
        $this->actingAs($seller)->getJson("/api/listings/{$listing->id}/euno-sign/status")
            ->assertJsonPath('data.next_signer', 'Olivia Owner')
            ->assertJsonPath('data.can_sign_now', true);

        // Owner signed: now the additional signer gets their link, the owner is waiting
        $ownerStatus = 'signed';
        $this->actingAs($extra)->getJson('/api/me/pending-signatures')->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.items.0.agreement_id', $agreement->id);
        $this->actingAs($seller)->getJson('/api/me/pending-signatures')->assertOk()->assertJsonPath('data.count', 0);
        $this->actingAs($seller)->getJson("/api/listings/{$listing->id}/euno-sign/status")
            ->assertJsonPath('data.next_signer', 'Sam Second')
            ->assertJsonPath('data.can_sign_now', false);
    }

    // --- Purchase agreement signers (original Reozom order) ---

    private function acceptedOffer(User $listingOwner, User $sender, array $ownerAnswers = []): ListingOffer
    {
        $listing = Listing::factory()->create(['user_id' => $listingOwner->id]);
        if ($ownerAnswers) {
            $listing->answers()->create(['step_id' => 'step_property_type_address', 'values' => $ownerAnswers]);
        }

        return ListingOffer::create([
            'listing_id' => $listing->id,
            'buyer_id' => $sender->id,
            'offer_amount' => 450000,
            'closing_date' => now()->addDays(30),
            'status' => 'accepted',
        ]);
    }

    public function test_offer_sent_by_an_agent_is_signed_by_selling_agent_purchasers_then_seller_side(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'email' => 'account@example.com']);
        $buyerAgent = User::factory()->create(['role' => 'agent', 'email' => 'buyeragent@example.com']);
        $offer = $this->acceptedOffer($seller, $buyerAgent, [
            'owner_type' => 'private',
            'owner_type_name' => 'Olivia Owner',
            'owner_type_email' => 'owner@example.com',
            'additional_signers' => 'Sam Second sam@example.com',
        ]);
        $offer->purchasers()->create(['first_name' => 'Pat', 'last_name' => 'Purchaser', 'email' => 'pat@example.com']);
        $offer->purchasers()->create(['first_name' => 'Dup', 'last_name' => 'Agent', 'email' => 'BuyerAgent@example.com']);

        $signers = $offer->fresh()->eSignSigners();

        $this->assertSame(
            ['buyeragent@example.com', 'pat@example.com', 'owner@example.com', 'sam@example.com'],
            array_column($signers, 'email')
        );
        $this->assertSame(['selling_agent', 'buyer', 'seller', 'additional_signer'], array_column($signers, 'role'));
    }

    public function test_agent_owned_listing_puts_the_listing_agent_first(): void
    {
        $listingAgent = User::factory()->create(['role' => 'agent', 'email' => 'listingagent@example.com']);
        $buyer = User::factory()->create(['role' => 'buyer', 'email' => 'buyer@example.com']);
        $offer = $this->acceptedOffer($listingAgent, $buyer, [
            'owner_type' => 'private',
            'owner_type_name' => 'Olivia Owner',
            'owner_type_email' => 'owner@example.com',
        ]);

        $signers = $offer->eSignSigners();

        $this->assertSame(['listingagent@example.com', 'buyer@example.com', 'owner@example.com'], array_column($signers, 'email'));
        $this->assertSame(['listing_agent', 'buyer', 'seller'], array_column($signers, 'role'));
    }

    public function test_purchase_agreement_is_sent_with_every_signer_and_a_purchaser_can_open_it(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'email' => 'owner@example.com']);
        $buyer = User::factory()->create(['role' => 'buyer', 'email' => 'buyer@example.com']);
        $purchaser = User::factory()->create(['role' => 'buyer', 'email' => 'pat@example.com']);
        $stranger = User::factory()->create(['role' => 'buyer']);
        $offer = $this->acceptedOffer($seller, $buyer);
        $offer->purchasers()->create(['first_name' => 'Pat', 'last_name' => 'Purchaser', 'email' => 'pat@example.com']);

        $this->actingAs($buyer)->postJson("/api/offers/{$offer->id}/euno-sign/initiate")->assertOk();

        $agreement = ListingEunoAgreement::where('listing_offer_id', $offer->id)->firstOrFail();
        $this->assertSame(['buyer@example.com', 'pat@example.com', 'owner@example.com'], array_column($agreement->signers, 'email'));
        $this->assertNotEmpty($agreement->signers[1]['recipient_id']);

        // The purchaser gets their own link to the same agreement; someone else is refused
        $this->actingAs($purchaser)->postJson("/api/offers/{$offer->id}/euno-sign/initiate")
            ->assertOk()
            ->assertJsonPath('data.agreement_id', $agreement->id)
            ->assertJsonPath('data.signing_url', fn ($url) => str_ends_with($url, '/'.$agreement->signers[1]['recipient_id']));
        $this->actingAs($stranger)->postJson("/api/offers/{$offer->id}/euno-sign/initiate")->assertForbidden();
        $this->assertSame(1, ListingEunoAgreement::where('listing_offer_id', $offer->id)->count());
    }

    public function test_purchase_agreement_pdf_has_one_signature_tag_per_signer(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'email' => 'owner@example.com']);
        $buyer = User::factory()->create(['role' => 'buyer', 'email' => 'buyer@example.com']);
        $offer = $this->acceptedOffer($seller, $buyer);
        $offer->purchasers()->create(['first_name' => 'Pat', 'last_name' => 'Purchaser', 'email' => 'pat@example.com']);

        $method = new \ReflectionMethod(\App\Http\Controllers\Api\OfferController::class, 'renderPurchaseAgreementHtml');
        $html = $method->invoke(app(\App\Http\Controllers\Api\OfferController::class), $offer->fresh());

        foreach (['$s1' => 'buyer@example.com', '$s2' => 'pat@example.com', '$s3' => 'owner@example.com'] as $tag => $email) {
            $this->assertStringContainsString($tag, $html);
            $this->assertStringContainsString($email, $html);
        }
        $this->assertStringNotContainsString('$s4', $html);
        $this->assertStringContainsString('Purchaser Signature', $html);
        $this->assertStringContainsString('Seller Signature', $html);
    }

    // --- Purchase agreement packet, resend, uploaded PA (original Reozom) ---

    /** @return array<int, string> file names sent to EunoDoc for the offer's latest agreement */
    private function packetFiles(ListingOffer $offer): array
    {
        $agreement = ListingEunoAgreement::where('listing_offer_id', $offer->id)->latest('id')->firstOrFail();

        return array_map('basename', array_values($agreement->source_file_paths ?? []));
    }

    public function test_purchase_agreement_packet_includes_lead_and_sellers_disclosure(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $seller = User::factory()->create(['role' => 'seller']);
        $buyer = User::factory()->create(['role' => 'buyer']);
        $offer = $this->acceptedOffer($seller, $buyer);

        $this->actingAs($buyer)->postJson("/api/offers/{$offer->id}/euno-sign/initiate")->assertOk();

        $files = $this->packetFiles($offer);
        $this->assertSame('Purchase_Agreement.pdf', $files[0]);
        $this->assertContains('sellers_disclosure.pdf', $files);
        $this->assertContains('lead_disclosure.pdf', $files);
        // Agreement-type listing documents are not part of the purchase agreement
        $this->assertNotContains('listing_agreement.pdf', $files);
    }

    public function test_disclosures_are_left_out_for_new_construction_trusts_and_agent_listings(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $buyer = User::factory()->create(['role' => 'buyer']);
        $cases = [
            'new construction' => [User::factory()->create(['role' => 'seller']), ['new_construction' => 'yes']],
            'non-owner-occupied trust' => [User::factory()->create(['role' => 'seller']), [
                'owner_type' => 'trust', 'owner_type_trust_type' => 'non_owner_occupied',
                'owner_type_trust_name' => 'Family Trust', 'owner_type_trust_email' => 'trust@example.com',
            ]],
            'agent listing' => [User::factory()->create(['role' => 'agent']), []],
        ];

        foreach ($cases as $case => [$owner, $answers]) {
            $offer = $this->acceptedOffer($owner, $buyer, $answers);
            $this->actingAs($buyer)->postJson("/api/offers/{$offer->id}/euno-sign/initiate")->assertOk();

            $this->assertSame(['Purchase_Agreement.pdf'], $this->packetFiles($offer), "Only the PA for: {$case}");
        }
    }

    public function test_disclosure_signature_tags_keep_purchase_agreement_numbers_and_skip_agents(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'email' => 'owner@example.com']);
        $buyerAgent = User::factory()->create(['role' => 'agent', 'email' => 'buyeragent@example.com']);
        $offer = $this->acceptedOffer($seller, $buyerAgent);
        $offer->purchasers()->create(['first_name' => 'Pat', 'last_name' => 'Purchaser', 'email' => 'pat@example.com']);
        $signers = $offer->fresh()->eSignSigners(); // 1 selling agent, 2 purchaser, 3 seller

        $docs = app(\App\Http\Controllers\Api\ListingDocumentController::class)->offerDisclosureDocuments($offer->listing);
        $lead = $docs->first(fn ($d) => $d->type === 'lead_disclosure');
        $this->assertNotNull($lead);

        $render = new \ReflectionMethod(\App\Http\Controllers\Api\ListingDocumentController::class, 'renderStatutoryHtml');
        $html = $render->invoke(app(\App\Http\Controllers\Api\ListingDocumentController::class), $offer->listing, $lead, [], $signers);

        foreach (['$s2', '$s3', '$i2', '$i3'] as $tag) {
            $this->assertStringContainsString($tag, $html);
        }
        $this->assertStringNotContainsString('$s1', $html, 'Agents do not sign the disclosures');
        $this->assertStringNotContainsString('$i1', $html);
        $this->assertStringContainsString('Buyer Signature', $html);
    }

    public function test_accepting_an_offer_closes_the_open_purchase_agreement(): void
    {
        $seller = User::factory()->create(['role' => 'seller']);
        $buyer = User::factory()->create(['role' => 'buyer']);
        $listing = Listing::factory()->create(['user_id' => $seller->id, 'status' => 'published']);
        $offer = ListingOffer::create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'status' => ListingOffer::STATUS_COUNTERED_BY_BUYER,
            'offer_amount' => 450000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);
        $old = ListingEunoAgreement::create([
            'listing_id' => $listing->id, 'listing_offer_id' => $offer->id, 'document_type' => 'purchase_agreement',
            'agreement_id' => 'mock_old_pa', 'status' => 'inprogress',
        ]);

        $this->actingAs($seller)->postJson("/api/offers/{$offer->id}/accept")->assertOk();

        $this->assertSame('canceled', $old->fresh()->status);
    }

    public function test_listing_owner_can_resend_the_purchase_agreement_but_buyer_cannot(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $seller = User::factory()->create(['role' => 'seller']);
        $buyer = User::factory()->create(['role' => 'buyer']);
        $offer = $this->acceptedOffer($seller, $buyer);
        $this->actingAs($buyer)->postJson("/api/offers/{$offer->id}/euno-sign/initiate")->assertOk();
        $first = ListingEunoAgreement::where('listing_offer_id', $offer->id)->firstOrFail();

        $this->actingAs($buyer)->postJson("/api/offers/{$offer->id}/euno-sign/resend")->assertForbidden();
        $this->actingAs($seller)->postJson("/api/offers/{$offer->id}/euno-sign/resend")->assertOk();

        $this->assertSame('canceled', $first->fresh()->status);
        $this->assertSame(1, ListingEunoAgreement::where('listing_offer_id', $offer->id)->where('status', 'inprogress')->count());
    }

    public function test_uploaded_purchase_agreement_is_never_sent_for_signature(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $seller = User::factory()->create(['role' => 'seller']);
        $buyer = User::factory()->create(['role' => 'buyer']);
        $offer = $this->acceptedOffer($seller, $buyer);
        \Illuminate\Support\Facades\Storage::disk('local')->put("offers/{$offer->id}/uploaded-pa.pdf", '%PDF-1.4 uploaded');
        \App\Models\ListingOfferDocument::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'listing_offer_id' => $offer->id, 'user_id' => $buyer->id, 'type' => 'purchase_agreement',
            'original_name' => 'my-pa.pdf', 'path' => "offers/{$offer->id}/uploaded-pa.pdf",
            'mime_type' => 'application/pdf', 'size' => 17,
        ]);

        $this->actingAs($buyer)->postJson("/api/offers/{$offer->id}/euno-sign/initiate")->assertOk();

        $files = $this->packetFiles($offer);
        $this->assertSame('Purchase_Agreement.pdf', $files[0]);
        $this->assertNotContains('uploaded-pa.pdf', $files);
    }

    public function test_euno_webhook_callback_processes_agreement(): void
    {
        $seller = User::factory()->create(['role' => 'seller']);
        $listing = Listing::factory()->create(['user_id' => $seller->id]);

        $agreement = ListingEunoAgreement::create([
            'listing_id' => $listing->id,
            'agreement_id' => 'mock_hook_999',
            'status' => 'inprogress',
        ]);

        $response = $this->postJson('/api/euno/webhook', [
            'agreementId' => 'mock_hook_999',
            'status' => 'completed',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('listing_euno_agreements', [
            'id' => $agreement->id,
            'status' => 'completed',
        ]);
    }
}
