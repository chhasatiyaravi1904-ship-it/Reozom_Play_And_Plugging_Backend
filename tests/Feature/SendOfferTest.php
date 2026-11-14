<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SendOfferTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;
    protected User $buyer;
    protected Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seller = User::factory()->create(['role' => 'seller']);
        $this->buyer = User::factory()->create([
            'role' => 'buyer',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ]);

        $this->listing = Listing::create([
            'user_id' => $this->seller->id,
            'status' => 'published',
            'address' => '742 Evergreen Terrace',
            'city' => 'Detroit',
            'state' => 'MI',
            'zip' => '48201',
        ]);
    }

    public function test_can_get_send_offer_configuration_for_listing(): void
    {
        Sanctum::actingAs($this->buyer);

        $response = $this->getJson("/api/listings/{$this->listing->id}/send-offer");

        $response->assertOk()
            ->assertJsonStructure([
                'listing' => [
                    'id',
                    'reference_code',
                    'address',
                    'city',
                    'state',
                    'zip',
                    'full_address',
                    'price',
                ],
                'buyer' => [
                    'id',
                    'first_name',
                    'last_name',
                    'email',
                ],
                'is_listing_owner',
                'has_open_offer',
                'fee_per_day',
                'home_warranty_price',
                'appliances',
                'payment_methods',
                'deadlines',
                'states',
            ]);

        $this->assertFalse($response->json('is_listing_owner'));
        $this->assertFalse($response->json('has_open_offer'));
        $this->assertGreaterThan(0, $response->json('fee_per_day'));
    }

    public function test_send_offer_config_identifies_listing_owner(): void
    {
        Sanctum::actingAs($this->seller);

        $response = $this->getJson("/api/listings/{$this->listing->id}/send-offer");

        $response->assertOk();
        $this->assertTrue($response->json('is_listing_owner'));
    }

    public function test_can_preview_example_purchase_agreement(): void
    {
        Sanctum::actingAs($this->buyer);

        $response = $this->get("/api/listings/{$this->listing->id}/example-purchase-agreement");

        $response->assertOk();
    }

    public function test_can_submit_official_offer_with_reozom_fields_and_purchasers(): void
    {
        Sanctum::actingAs($this->buyer);

        $file = UploadedFile::fake()->create('pre_approval_letter.pdf', 500, 'application/pdf');

        $payload = [
            'offer_amount' => 345000,
            'earnest_money_deposit' => 5000,
            'down_payment_amount' => 69000,
            'payment_method' => 'conventional',
            'mortgage_application_provided_by' => now()->addDays(5)->format('Y-m-d'),
            'loan_approval_provided_by' => now()->addDays(20)->format('Y-m-d'),
            'closing_date' => now()->addDays(35)->format('Y-m-d'),
            'occupancy_type' => 'days_after_closing',
            'occupancy_days' => 30,
            'fee_per_day' => 60,
            'seller_concessions' => 3, // 3%
            'closing_amount_type' => 1, // 1 - %
            'home_warranty_included' => true,
            'deadline' => 48,
            'main_purchaser_first_name' => 'John',
            'main_purchaser_last_name' => 'Doe',
            'main_purchaser_email' => 'john.doe@example.com',
            'main_purchaser_non_us_citizen' => false,
            'purchasers' => [
                [
                    'first_name' => 'Jane',
                    'last_name' => 'Doe',
                    'email' => 'jane.doe@example.com',
                    'non_us_citizen' => false,
                ],
            ],
            'contingencies' => [
                'property_inspection' => true,
                'property_inspection_days' => 10,
                'survey' => false,
                'attorney_review' => true,
            ],
            'appliances' => ['Refrigerator', 'Stove', 'Microwave'],
            'other_appliances' => 'Second Freezer',
            'comment' => 'Official offer matching Reozom standards.',
            'document' => $file,
        ];

        $response = $this->postJson("/api/listings/{$this->listing->id}/offers", $payload);

        $response->assertCreated();

        $this->assertDatabaseHas('listing_offers', [
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer->id,
            'status' => 'submitted',
            'offer_amount' => 345000,
            'earnest_money_deposit' => 5000,
            'payment_method' => 'conventional',
            'occupancy_type' => 'days_after_closing',
            'occupancy_days' => 30,
            'fee_per_day' => 60,
        ]);

        $offer = ListingOffer::where('listing_id', $this->listing->id)->first();
        $this->assertNotNull($offer);

        // Check Purchasers
        $this->assertCount(2, $offer->purchasers);

        // Check Document
        $this->assertCount(1, $offer->documents);

        // Check Action History
        $this->assertCount(1, $offer->actions);
        $this->assertEquals('submitted', $offer->actions->first()->action_type);

        // Check Reozom Bottom Line Calculation: 345,000 - 3% (10,350) = 334,650
        $this->assertEquals(334650, $offer->bottom_line_offer);
        $this->assertEquals(97.0, $offer->percentage);
    }

    public function test_owner_cannot_submit_offer_to_own_listing(): void
    {
        Sanctum::actingAs($this->seller);

        $payload = [
            'offer_amount' => 350000,
            'closing_date' => now()->addDays(30)->format('Y-m-d'),
            'payment_method' => 'cash',
        ];

        $response = $this->postJson("/api/listings/{$this->listing->id}/offers", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['buyer']);
    }

    public function test_admin_can_download_offer_contract(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $offer = ListingOffer::create([
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer->id,
            'status' => 'submitted',
            'offer_amount' => 300000,
            'earnest_money_deposit' => 3000,
            'payment_method' => 'conventional',
            'closing_date' => now()->addDays(30)->format('Y-m-d'),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->get("/api/offers/{$offer->id}/contract/download");

        $response->assertOk();
    }
}
