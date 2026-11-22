<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingEunoAgreement;
use App\Models\ListingOffer;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Purchase agreement e-signing on an offer: one shared agreement, a personal link per
 * signer, and only the offer's parties can see it.
 */
class OfferPurchaseAgreementSigningTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $buyer;
    private User $agent;
    private ListingOffer $offer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['euno.mock_mode' => true]);
        Storage::fake('local');

        $this->seller = User::factory()->create(['role' => 'seller_buyer', 'email' => 'seller.pa@example.com']);
        $this->buyer = User::factory()->create(['role' => 'seller_buyer', 'email' => 'buyer.pa@example.com']);
        $this->agent = User::factory()->create(['role' => 'agent']);
        $plan = ServicePackage::factory()->create(['agent_id' => $this->agent->id]);

        $listing = Listing::create([
            'user_id' => $this->seller->id, 'service_package_id' => $plan->id, 'status' => 'under_contract',
            'address' => '1 Main St', 'city' => 'Detroit', 'state' => 'MI', 'zip' => '48201',
        ]);
        $this->offer = ListingOffer::create([
            'listing_id' => $listing->id, 'buyer_id' => $this->buyer->id,
            'status' => ListingOffer::STATUS_ACCEPTED, 'offer_amount' => 400000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    private function initiate(User $user)
    {
        return $this->actingAs($user)->postJson("/api/offers/{$this->offer->id}/euno-sign/initiate");
    }

    public function test_buyer_and_seller_sign_the_same_agreement_with_their_own_links(): void
    {
        $sellerRes = $this->initiate($this->seller)->assertOk();
        $buyerRes = $this->initiate($this->buyer)->assertOk();

        // Only one agreement for the offer
        $this->assertSame(1, ListingEunoAgreement::where('listing_offer_id', $this->offer->id)->count());
        $this->assertSame($sellerRes->json('data.agreement_id'), $buyerRes->json('data.agreement_id'));

        // Each signer gets the link for their own recipient id
        $agreement = ListingEunoAgreement::firstOrFail();
        // Signers are stored in signing order with their EunoDoc recipient id
        $ids = collect($agreement->signers)->pluck('recipient_id', 'email');
        $this->assertStringEndsWith('/'.$ids['seller.pa@example.com'], $sellerRes->json('data.signing_url'));
        $this->assertStringEndsWith('/'.$ids['buyer.pa@example.com'], $buyerRes->json('data.signing_url'));
    }

    public function test_a_new_agreement_is_created_once_the_previous_one_is_finished(): void
    {
        $this->initiate($this->seller)->assertOk();
        ListingEunoAgreement::query()->update(['status' => 'completed']);

        $this->initiate($this->buyer)->assertOk();
        $this->assertSame(2, ListingEunoAgreement::where('listing_offer_id', $this->offer->id)->count());
    }

    public function test_signing_starts_only_after_the_offer_is_accepted(): void
    {
        foreach ([ListingOffer::STATUS_SUBMITTED, ListingOffer::STATUS_COUNTERED_BY_SELLER, ListingOffer::STATUS_REJECTED] as $status) {
            $this->offer->update(['status' => $status]);
            $this->initiate($this->seller)
                ->assertStatus(422)
                ->assertJsonPath('message', 'The purchase agreement can be signed once the offer is accepted.');
        }
        $this->assertSame(0, ListingEunoAgreement::count());

        $this->offer->update(['status' => ListingOffer::STATUS_ACCEPTED]);
        $this->initiate($this->seller)->assertOk();
        $this->assertSame(1, ListingEunoAgreement::count());
    }

    public function test_only_the_offers_parties_can_see_the_signing_status(): void
    {
        $this->initiate($this->seller)->assertOk();
        $url = "/api/offers/{$this->offer->id}/euno-sign/status";

        $stranger = User::factory()->create(['role' => 'seller_buyer']);
        $otherAgent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($stranger)->getJson($url)->assertForbidden();
        $this->actingAs($otherAgent)->getJson($url)->assertForbidden();
        $this->actingAs($stranger)->postJson("/api/offers/{$this->offer->id}/euno-sign/initiate")->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        foreach ([$this->buyer, $this->seller, $this->agent, $admin] as $user) {
            $this->actingAs($user)->getJson($url)->assertOk();
        }
    }
}
