<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingOffer;
use App\Models\ListingSoldDetails;
use App\Models\ListingStatusHistory;
use App\Models\User;
use App\Services\ListingStatusService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReozomOfferAndStatusWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;
    protected User $buyer;
    protected User $agent;
    protected User $admin;
    protected Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create(['role' => 'seller']);
        $this->buyer = User::factory()->create(['role' => 'buyer']);
        $this->agent = User::factory()->create(['role' => 'agent']);
        $this->admin = User::factory()->create(['role' => 'admin']);

        $this->listing = Listing::create([
            'user_id' => $this->seller->id,
            'status' => 'published',
            'address' => '789 Woodward Ave',
            'city' => 'Detroit',
            'state' => 'MI',
            'zip' => '48226',
        ]);
    }

    public function test_send_offer_with_complete_reozom_fields_and_calculations(): void
    {
        $payload = [
            'offer_amount' => 300000,
            'earnest_money_deposit' => 6000,
            'down_payment_amount' => 60000,
            'payment_method' => 'conventional',
            'closing_date' => now()->addDays(45)->toDateString(),
            'occupancy_type' => 'days_after_closing',
            'occupancy_days' => 30,
            'fee_per_day' => 50,
            'seller_concessions' => 6000,
            'closing_amount_type' => 2, // Fixed $
            'commission' => 3, // 3%
            'commission_type' => 1, // Percentage
            'has_agent_representation' => true,
            'agent_name' => 'Jane Agent',
            'home_warranty_included' => true,
            'home_warranty_price' => 499,
            'contingencies' => [
                'property_inspection' => true,
                'property_inspection_days' => 10,
                'lead_paint_inspection' => false,
                'sale_of_home' => false,
                'well_inspection' => false,
                'septic_inspection' => false,
            ],
            'deadline' => 48, // 48 hours deadline option
            'main_purchaser_first_name' => 'John',
            'main_purchaser_last_name' => 'Doe',
            'main_purchaser_email' => 'john.doe@example.com',
            'main_purchaser_address' => '100 Main St',
            'main_purchaser_city' => 'Troy',
            'main_purchaser_state' => 'MI',
            'main_purchaser_zip' => '48084',
            'purchasers' => [
                [
                    'first_name' => 'Mary',
                    'last_name' => 'Doe',
                    'email' => 'mary.doe@example.com',
                ]
            ],
            'comment' => 'Official purchase agreement submitted per Reozom terms.',
        ];

        $response = $this->actingAs($this->buyer)->postJson("/api/listings/{$this->listing->id}/offers", $payload);

        $response->assertStatus(201);
        $offer = ListingOffer::where('listing_id', $this->listing->id)->firstOrFail();

        $this->assertEquals(300000, $offer->offer_amount);
        $this->assertEquals(6000, $offer->earnest_money_deposit);
        $this->assertEquals(ListingOffer::STATUS_SUBMITTED, $offer->status);
        $this->assertTrue($offer->has_agent_representation);
        $this->assertTrue($offer->home_warranty_included);
        $this->assertEquals(499, $offer->home_warranty_price);
        $this->assertEquals(50, $offer->fee_per_day);

        // Check purchasers
        $this->assertCount(2, $offer->purchasers);

        // Reozom Bottom Line Calculations:
        // Offer: 300,000, Concessions: 6,000 -> Bottom Line: 294,000
        $this->assertEquals(294000.00, $offer->bottom_line_offer);
        $this->assertEquals(98.0, $offer->percentage);

        // Full Bottom Line deducting 3% commission on 294,000 (8,820) -> 285,180
        $this->assertEquals(285180.00, $offer->full_bottom_line_offer);
        $this->assertEquals(95.06, $offer->full_percentage);

        // Check action logged
        $this->assertDatabaseHas('listing_offer_actions', [
            'listing_offer_id' => $offer->id,
            'actor_type' => 'buyer',
            'action_type' => 'submitted',
        ]);
    }

    public function test_seller_cannot_send_offer_to_own_listing(): void
    {
        $response = $this->actingAs($this->seller)->postJson("/api/listings/{$this->listing->id}/offers", [
            'offer_amount' => 250000,
            'closing_date' => now()->addDays(30)->toDateString(),
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['buyer']);
    }

    public function test_counter_offer_workflow_and_state_transitions(): void
    {
        $offer = ListingOffer::create([
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer->id,
            'status' => ListingOffer::STATUS_SUBMITTED,
            'offer_amount' => 280000,
            'earnest_money_deposit' => 5000,
            'seller_concessions' => 5000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);

        // Seller cannot accept their own counter before sending
        // Seller counters buyer
        $counterRes1 = $this->actingAs($this->seller)->postJson("/api/offers/{$offer->id}/counter", [
            'offer_amount' => 295000,
            'seller_concessions' => 2000,
            'comment' => 'Reduced seller concessions and increased purchase price.',
        ]);

        $counterRes1->assertStatus(200);
        $this->assertEquals(ListingOffer::STATUS_COUNTERED_BY_SELLER, $offer->fresh()->status);
        $this->assertEquals(295000, $offer->fresh()->offer_amount);
        $this->assertEquals(2000, $offer->fresh()->seller_concessions);

        // Seller cannot counter again while waiting for buyer response
        $invalidCounterRes = $this->actingAs($this->seller)->postJson("/api/offers/{$offer->id}/counter", [
            'offer_amount' => 290000,
        ]);
        $invalidCounterRes->assertStatus(422);

        // Buyer counters back to seller
        $counterRes2 = $this->actingAs($this->buyer)->postJson("/api/offers/{$offer->id}/counter", [
            'offer_amount' => 290000,
            'seller_concessions' => 3000,
            'comment' => 'Split the difference.',
        ]);

        $counterRes2->assertStatus(200);
        $this->assertEquals(ListingOffer::STATUS_COUNTERED_BY_BUYER, $offer->fresh()->status);
        $this->assertEquals(290000, $offer->fresh()->offer_amount);
    }

    public function test_counter_offer_rejects_identical_unmodified_terms(): void
    {
        $offer = ListingOffer::create([
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer->id,
            'status' => ListingOffer::STATUS_SUBMITTED,
            'offer_amount' => 280000,
            'earnest_money_deposit' => 5000,
            'seller_concessions' => 5000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);

        // Submitting identical terms without any modification
        $response = $this->actingAs($this->seller)->postJson("/api/offers/{$offer->id}/counter", [
            'offer_amount' => 280000,
            'earnest_money_deposit' => 5000,
            'seller_concessions' => 5000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['offer']);
    }

    public function test_accept_offer_transitions_to_ccs_when_contingent_on_buyer_home_sale(): void
    {
        $offer = ListingOffer::create([
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer->id,
            'status' => ListingOffer::STATUS_SUBMITTED,
            'offer_amount' => 320000,
            'earnest_money_deposit' => 5000,
            'closing_date' => now()->addDays(60)->toDateString(),
            'contingencies' => [
                'sale_of_home' => true,
                'property_inspection' => true,
            ],
            'has_agent_representation' => true,
            'agent_name' => 'Robert Broker',
            'payment_method' => 'conventional',
        ]);

        $response = $this->actingAs($this->seller)->postJson("/api/offers/{$offer->id}/accept", [
            'comment' => 'Accepted with Contingent - Continue to Show clause.',
        ]);

        $response->assertStatus(200);
        $this->assertEquals(ListingOffer::STATUS_ACCEPTED, $offer->fresh()->status);

        // Listing transitions to CCS (Contingent Continue to Show) per Reozom rules
        $this->assertEquals('ccs', $this->listing->fresh()->status);

        // Verify closing details were populated in listing_sold_details
        $soldDetails = ListingSoldDetails::where('listing_id', $this->listing->id)->firstOrFail();
        $this->assertEquals(320000, $soldDetails->sales_price);
        $this->assertEquals('Robert Broker', $soldDetails->agent_name);
        $this->assertEquals('conventional', $soldDetails->finance_type);
    }

    public function test_decline_and_withdraw_offer_workflows(): void
    {
        $offer = ListingOffer::create([
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer->id,
            'status' => ListingOffer::STATUS_SUBMITTED,
            'offer_amount' => 250000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);

        // Seller rejects offer
        $rejectRes = $this->actingAs($this->seller)->postJson("/api/offers/{$offer->id}/reject", [
            'reason' => 'Price is too low for current market.',
        ]);

        $rejectRes->assertStatus(200);
        $this->assertEquals(ListingOffer::STATUS_REJECTED, $offer->fresh()->status);

        // Second offer withdrawn by buyer
        $offer2 = ListingOffer::create([
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer->id,
            'status' => ListingOffer::STATUS_SUBMITTED,
            'offer_amount' => 290000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);

        $withdrawRes = $this->actingAs($this->buyer)->postJson("/api/offers/{$offer2->id}/withdraw", [
            'reason' => 'Found an alternative home.',
        ]);

        $withdrawRes->assertStatus(200);
        $this->assertEquals(ListingOffer::STATUS_WITHDRAWN, $offer2->fresh()->status);
    }

    public function test_reozom_listing_status_transitions_and_admin_management(): void
    {
        $statusService = app(ListingStatusService::class);

        // 1. Published -> Suspended
        $statusService->transition($this->listing, 'suspended', $this->seller, 'seller', 'Owner traveling');
        $this->assertEquals('suspended', $this->listing->fresh()->status);

        // 2. Suspended -> Published (Reactivated)
        $statusService->transition($this->listing, 'published', $this->seller, 'seller', 'Back on market');
        $this->assertEquals('published', $this->listing->fresh()->status);

        // 3. Published -> CCS (Contingent Continue to Show)
        $statusService->transition(
            $this->listing,
            'ccs',
            $this->seller,
            'seller',
            'Offer accepted with home sale contingency',
            ['anticipated_closed_date' => '2026-12-01', 'agent' => 1, 'agent_name' => 'Agent Smith']
        );
        $this->assertEquals('ccs', $this->listing->fresh()->status);

        // 4. CCS -> Under Contract (Pending)
        $statusService->transition(
            $this->listing,
            'under_contract',
            $this->seller,
            'seller',
            'Buyer home sold; contingency removed',
            ['anticipated_closed_date' => '2026-12-01']
        );
        $this->assertEquals('under_contract', $this->listing->fresh()->status);

        // 5. Under Contract -> Sold with full Reozom Sold Details
        $statusService->transition(
            $this->listing,
            'sold',
            $this->seller,
            'seller',
            'Deal closed successfully',
            [
                'closed_date' => '2026-12-01',
                'sales_price' => 315000,
                'sellers_concessions' => 1, // Yes
                'amount' => 5000,
                'finance_type' => 'conventional',
                'agent' => 1,
                'agent_name' => 'Agent Smith',
            ]
        );
        $this->assertEquals('sold', $this->listing->fresh()->status);

        // Verify Sold Details
        $sold = ListingSoldDetails::where('listing_id', $this->listing->id)->firstOrFail();
        $this->assertEquals(315000, $sold->sales_price);
        $this->assertEquals(5000, $sold->amount);
        $this->assertEquals(1, $sold->sellers_concessions);

        // 6. Admin changeStatus API endpoint (replicating reozom_administration)
        $adminRes = $this->actingAs($this->admin)->postJson("/api/admin/listings/{$this->listing->id}/change-status", [
            'status' => 'published',
            'comment' => 'Reopened by broker per client request.',
        ]);

        $adminRes->assertStatus(200);
        $this->assertEquals('published', $this->listing->fresh()->status);

        // Check history records
        $history = ListingStatusHistory::where('listing_id', $this->listing->id)->get();
        $this->assertGreaterThanOrEqual(6, $history->count());
    }

    public function test_reozom_numeric_status_mapping(): void
    {
        $statusService = app(ListingStatusService::class);

        // Reozom numeric: 1 is Active/Published, 2 is Pending/Under Contract, 3 is CCS, 4 is Sold, 6 is Suspended
        $this->assertEquals('published', ListingStatusService::normalizeStatus(1));
        $this->assertEquals('under_contract', ListingStatusService::normalizeStatus(2));
        $this->assertEquals('ccs', ListingStatusService::normalizeStatus(3));
        $this->assertEquals('sold', ListingStatusService::normalizeStatus(4));
        $this->assertEquals('suspended', ListingStatusService::normalizeStatus(6));

        // Test transition using numeric status code 6 (Suspended)
        $statusService->transition($this->listing, 6, $this->admin, 'admin', 'Suspended via code', null, true);
        $this->assertEquals('suspended', $this->listing->fresh()->status);

        // Test transition using numeric status code 1 (Published/Active)
        $statusService->transition($this->listing, 1, $this->admin, 'admin', 'Published via code', null, true);
        $this->assertEquals('published', $this->listing->fresh()->status);
    }
}

