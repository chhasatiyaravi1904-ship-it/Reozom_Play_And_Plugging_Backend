<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfferManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;
    protected User $buyer1;
    protected User $buyer2;
    protected Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create(['role' => 'seller']);
        $this->buyer1 = User::factory()->create(['role' => 'buyer']);
        $this->buyer2 = User::factory()->create(['role' => 'buyer']);

        $this->listing = Listing::create([
            'user_id' => $this->seller->id,
            'status' => 'published',
            'address' => '456 Ocean Dr',
            'city' => 'Miami',
            'state' => 'FL',
            'zip' => '33139',
        ]);
    }

    public function test_buyer_can_submit_offer(): void
    {
        $payload = [
            'offer_amount' => 450000,
            'earnest_money_deposit' => 10000,
            'payment_method' => 'conventional',
            'closing_date' => now()->addDays(30)->toDateString(),
            'occupancy_type' => 'at_closing',
            'seller_concessions' => 5000,
            'contingencies' => [
                'property_inspection' => true,
                'financing' => true,
            ],
            'comment' => 'Clean offer with pre-approval.',
        ];

        $response = $this->actingAs($this->buyer1)->postJson("/api/listings/{$this->listing->id}/offers", $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('offer.offer_amount', '450000.00');
        $response->assertJsonPath('offer.status', 'submitted');

        $this->assertDatabaseHas('listing_offers', [
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer1->id,
            'status' => 'submitted',
        ]);
    }

    public function test_seller_can_counter_offer(): void
    {
        $offer = ListingOffer::create([
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer1->id,
            'status' => ListingOffer::STATUS_SUBMITTED,
            'offer_amount' => 400000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);

        $counterPayload = [
            'offer_amount' => 420000,
            'comment' => 'Split the difference.',
        ];

        $response = $this->actingAs($this->seller)->postJson("/api/offers/{$offer->id}/counter", $counterPayload);

        $response->assertStatus(200);
        $response->assertJsonPath('offer.status', 'countered_by_seller');
        $response->assertJsonPath('offer.offer_amount', '420000.00');
    }

    public function test_accepting_offer_atomically_transitions_listing_and_handles_competing_offers(): void
    {
        // Offer 1 from Buyer 1
        $offer1 = ListingOffer::create([
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer1->id,
            'status' => ListingOffer::STATUS_SUBMITTED,
            'offer_amount' => 450000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);

        // Offer 2 from Buyer 2
        $offer2 = ListingOffer::create([
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer2->id,
            'status' => ListingOffer::STATUS_SUBMITTED,
            'offer_amount' => 440000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);

        // Seller accepts Offer 1
        $response = $this->actingAs($this->seller)->postJson("/api/offers/{$offer1->id}/accept", [
            'comment' => 'Accepted full asking price.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('offer.status', 'accepted');

        // Listing must now be under_contract
        $this->assertEquals('under_contract', $this->listing->fresh()->status);

        // Competing offer must automatically be rejected_under_contract
        $this->assertEquals('rejected_under_contract', $offer2->fresh()->status);
    }

    public function test_buyer_can_withdraw_pending_offer(): void
    {
        $offer = ListingOffer::create([
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer1->id,
            'status' => ListingOffer::STATUS_SUBMITTED,
            'offer_amount' => 400000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);

        $response = $this->actingAs($this->buyer1)->postJson("/api/offers/{$offer->id}/withdraw", [
            'reason' => 'Found another home.',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('withdrawn', $offer->fresh()->status);
    }

    public function test_admin_can_view_all_offers_with_metrics_and_filters(): void
    {
        $offer = ListingOffer::create([
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer1->id,
            'status' => ListingOffer::STATUS_SUBMITTED,
            'offer_amount' => 415000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->getJson('/api/admin/offers');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'offers' => [
                'data',
                'current_page',
                'total',
            ],
            'metrics' => [
                'total_offers',
                'active_offers',
                'accepted_offers',
                'accepted_volume',
                'rejected_offers',
            ],
        ]);
    }

    public function test_admin_can_view_single_offer_detail(): void
    {
        $offer = ListingOffer::create([
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer1->id,
            'status' => ListingOffer::STATUS_SUBMITTED,
            'offer_amount' => 430000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->getJson("/api/admin/offers/{$offer->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('id', $offer->id);
        $response->assertJsonPath('offer_amount', '430000.00');
    }
}
