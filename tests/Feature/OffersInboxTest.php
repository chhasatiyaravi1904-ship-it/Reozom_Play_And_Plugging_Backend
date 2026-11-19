<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingOffer;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Offers" page: Received / Sent tabs, an agent's client listings, and who may
 * read the offers on a listing.
 */
class OffersInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $agent;
    private User $buyer;
    private User $otherBuyer;
    private Listing $sellerListing;   // seller's listing on the agent's plan
    private Listing $agentListing;    // listing the agent created themselves
    private ListingOffer $offerOnSellerListing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create(['role' => 'seller']);
        $this->agent = User::factory()->create(['role' => 'agent']);
        $this->buyer = User::factory()->create(['role' => 'buyer']);
        $this->otherBuyer = User::factory()->create(['role' => 'buyer']);

        $plan = ServicePackage::factory()->create(['agent_id' => $this->agent->id]);

        $this->sellerListing = Listing::create([
            'user_id' => $this->seller->id, 'service_package_id' => $plan->id, 'status' => 'published',
            'address' => '1 Main St', 'city' => 'Detroit', 'state' => 'MI', 'zip' => '48201',
        ]);
        $this->agentListing = Listing::create([
            'user_id' => $this->agent->id, 'status' => 'published',
            'address' => '2 Oak St', 'city' => 'Detroit', 'state' => 'MI', 'zip' => '48201',
        ]);

        $this->offerOnSellerListing = $this->offer($this->sellerListing, $this->buyer);
        $this->offer($this->agentListing, $this->buyer);
        // The agent also made an offer as a buyer on someone else's listing
        $this->offer(Listing::create([
            'user_id' => $this->otherBuyer->id, 'status' => 'published',
            'address' => '3 Elm St', 'city' => 'Detroit', 'state' => 'MI', 'zip' => '48201',
        ]), $this->agent);
    }

    private function offer(Listing $listing, User $buyer): ListingOffer
    {
        return ListingOffer::create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'status' => ListingOffer::STATUS_SUBMITTED,
            'offer_amount' => 400000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);
    }

    private function inbox(User $user, string $involvement)
    {
        return $this->actingAs($user)->getJson("/api/offers/involvement?involvement={$involvement}")->assertOk();
    }

    public function test_seller_receives_offers_on_own_listings_only(): void
    {
        $res = $this->inbox($this->seller, 'received');

        $this->assertSame([$this->offerOnSellerListing->id], collect($res->json('offers.data'))->pluck('id')->all());
        $res->assertJsonPath('counts.received', 1)->assertJsonPath('counts.sent', 0);
        $res->assertJsonPath('offers.data.0.my_role', 'seller');
    }

    public function test_agent_receives_offers_on_own_and_client_listings(): void
    {
        $res = $this->inbox($this->agent, 'received');

        $listingIds = collect($res->json('offers.data'))->pluck('listing_id')->sort()->values()->all();
        $this->assertSame(collect([$this->sellerListing->id, $this->agentListing->id])->sort()->values()->all(), $listingIds);
        $res->assertJsonPath('counts.received', 2)->assertJsonPath('counts.sent', 1);

        $roles = collect($res->json('offers.data'))->pluck('my_role', 'listing_id');
        $this->assertSame('agent', $roles[$this->sellerListing->id]);   // client's listing: view only
        $this->assertSame('seller', $roles[$this->agentListing->id]);  // agent's own listing
    }

    public function test_agent_client_offers_are_not_action_required_for_the_agent(): void
    {
        $offers = collect($this->inbox($this->agent, 'received')->json('offers.data'))->keyBy('listing_id');

        $this->assertFalse($offers[$this->sellerListing->id]['is_action_required']);
        $this->assertTrue($offers[$this->agentListing->id]['is_action_required']);
    }

    public function test_sent_tab_lists_offers_made_as_buyer(): void
    {
        $res = $this->inbox($this->agent, 'sent');
        $res->assertJsonCount(1, 'offers.data')->assertJsonPath('offers.data.0.my_role', 'buyer');

        $this->inbox($this->buyer, 'sent')->assertJsonCount(2, 'offers.data');
    }

    public function test_per_page_is_capped(): void
    {
        $this->inbox($this->buyer, 'sent&per_page=100000')->assertJsonPath('offers.per_page', 50);
    }

    // --- Security: competing buyers must not see other offers on a listing ---

    public function test_other_users_cannot_read_a_listings_offers(): void
    {
        $this->actingAs($this->otherBuyer)->getJson("/api/listings/{$this->sellerListing->id}/offers")->assertForbidden();
        $this->actingAs($this->otherBuyer)->getJson("/api/seller/offers/{$this->offerOnSellerListing->id}")->assertForbidden();
        $this->actingAs($this->otherBuyer)->getJson("/api/offers/{$this->offerOnSellerListing->id}")->assertForbidden();
        // Even the buyer of one offer can't list all offers on the listing
        $this->actingAs($this->buyer)->getJson("/api/listings/{$this->sellerListing->id}/offers")->assertForbidden();
    }

    public function test_owner_plan_agent_and_admin_can_read_a_listings_offers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([$this->seller, $this->agent, $admin] as $user) {
            $this->actingAs($user)->getJson("/api/listings/{$this->sellerListing->id}/offers")->assertOk();
            $this->actingAs($user)->getJson("/api/seller/offers/{$this->offerOnSellerListing->id}")->assertOk();
            $this->actingAs($user)->getJson("/api/offers/{$this->offerOnSellerListing->id}")->assertOk();
        }
        // The buyer still sees their own offer
        $this->actingAs($this->buyer)->getJson("/api/offers/{$this->offerOnSellerListing->id}")->assertOk();
    }

    public function test_offer_documents_and_contract_follow_the_same_access_rule(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('offers/doc.pdf', '%PDF-test');
        $doc = \App\Models\ListingOfferDocument::create([
            'listing_offer_id' => $this->offerOnSellerListing->id,
            'user_id' => $this->buyer->id,
            'type' => 'pre_approval_letter',
            'original_name' => 'letter.pdf',
            'path' => 'offers/doc.pdf',
            'mime_type' => 'application/pdf',
            'size' => 9,
        ]);
        $id = $this->offerOnSellerListing->id;
        $docUrl = "/api/offers/{$id}/documents/{$doc->id}/download";

        // Competing buyer: neither the attachment nor the contract
        $this->actingAs($this->otherBuyer)->get($docUrl)->assertForbidden();
        $this->actingAs($this->otherBuyer)->get("/api/offers/{$id}/contract/download")->assertForbidden();

        // Buyer, seller and the listing's agent can download the attachment
        foreach ([$this->buyer, $this->seller, $this->agent] as $user) {
            $this->actingAs($user)->get($docUrl)->assertOk();
        }
    }

    public function test_send_offer_form_is_not_available_for_unpublished_listings_of_others(): void
    {
        $draft = Listing::create([
            'user_id' => $this->seller->id, 'status' => 'draft',
            'address' => '9 Secret Rd', 'city' => 'Detroit', 'state' => 'MI', 'zip' => '48201',
        ]);

        $this->actingAs($this->buyer)->getJson("/api/listings/{$draft->id}/send-offer")->assertForbidden();
        $this->actingAs($this->buyer)->get("/api/listings/{$draft->id}/example-purchase-agreement")->assertForbidden();
        // A published listing still works
        $this->actingAs($this->buyer)->getJson("/api/listings/{$this->sellerListing->id}/send-offer")->assertOk();
    }

    public function test_public_listing_says_whether_the_viewer_owns_it(): void
    {
        $url = "/api/public/listings/{$this->sellerListing->id}";
        $ownerToken = $this->seller->createToken('t')->plainTextToken;
        $buyerToken = $this->buyer->createToken('t')->plainTextToken;

        $this->getJson($url)->assertOk()->assertJsonPath('data.is_owner', false);
        $this->withToken($buyerToken)->getJson($url)->assertOk()->assertJsonPath('data.is_owner', false);
        $this->app['auth']->forgetGuards();
        $this->withToken($ownerToken)->getJson($url)->assertOk()->assertJsonPath('data.is_owner', true);
    }

    public function test_owner_cannot_submit_an_offer_on_own_listing(): void
    {
        $this->actingAs($this->seller)->postJson("/api/listings/{$this->sellerListing->id}/offers", [
            'offer_amount' => 400000,
            'closing_date' => now()->addDays(30)->toDateString(),
            'payment_method' => 'cash',
        ])->assertStatus(422)->assertJsonPath('errors.buyer.0', 'You are the owner of this listing. You cannot submit an offer to yourself.');

        $this->assertSame(0, ListingOffer::where('buyer_id', $this->seller->id)->count());
    }

    public function test_agent_cannot_act_on_a_clients_offer(): void
    {
        $id = $this->offerOnSellerListing->id;

        $this->actingAs($this->agent)->postJson("/api/offers/{$id}/accept")->assertForbidden();
        $this->actingAs($this->agent)->postJson("/api/offers/{$id}/reject")->assertForbidden();
        $this->actingAs($this->agent)->postJson("/api/offers/{$id}/counter", ['offer_amount' => 410000])->assertForbidden();
        $this->assertSame(ListingOffer::STATUS_SUBMITTED, $this->offerOnSellerListing->fresh()->status);
    }
}
