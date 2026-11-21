<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AgentPackage;
use App\Models\Listing;
use App\Models\ListingEunoAgreement;
use App\Models\ListingOffer;
use App\Models\ListingProcess;
use App\Models\Package;
use App\Models\User;
use App\Models\ZipCode;
use Database\Seeders\DocumentTemplateSeeder;
use Database\Seeders\FloridaListingProcessSeeder;
use Database\Seeders\ReozomStatutoryDisclosuresSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The whole marketplace journey through the real API, one step after another:
 * agent plan + listing process → seller creates the listing → answers every step →
 * signs the listing documents → publishes → buyer offers → seller accepts →
 * purchase agreement is signed. EunoDoc runs in mock mode (no network).
 */
class EndToEndListingToPurchaseAgreementTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private User $seller;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['euno.mock_mode' => true]);
        Storage::fake('local');

        $this->seed(ReozomStatutoryDisclosuresSeeder::class);
        $this->seed(DocumentTemplateSeeder::class);
        // The Florida seeder attaches its demo plan to this agent account
        $this->agent = User::factory()->create(['role' => UserRole::Agent, 'email' => 'agent@example.com', 'is_active' => true]);
        $this->seed(FloridaListingProcessSeeder::class);

        $this->seller = User::factory()->create(['role' => UserRole::SellerBuyer, 'name' => 'Sam Seller', 'email' => 'sam.seller@example.com']);
        $this->buyer = User::factory()->create(['role' => UserRole::SellerBuyer, 'name' => 'Bea Buyer', 'email' => 'bea.buyer@example.com']);

        // The agent needs an active subscription for sellers to pick their plan
        $subscription = Package::firstOrCreate(
            ['slug' => 'agent-pro'],
            ['name' => 'Agent Pro', 'role_name' => 'agent-pro', 'price' => 50, 'duration_days' => 30, 'is_active' => true]
        );
        AgentPackage::create([
            'user_id' => $this->agent->id, 'package_id' => $subscription->id,
            'started_at' => now(), 'expires_at' => now()->addDays(30),
        ]);

        // The Florida seeder already creates ZIP 33139; make sure it is active
        ZipCode::where('code', '33139')->first()?->update(['is_active' => true])
            ?? ZipCode::factory()->create(['code' => '33139', 'is_active' => true]);
    }

    public function test_listing_process_to_signed_purchase_agreement(): void
    {
        // 1. Agent creates a free service package on the Florida listing process for ZIP 33139
        $process = ListingProcess::where('name', 'Florida Residential Listing Process')->firstOrFail();
        $packageId = $this->actingAs($this->agent)->postJson('/api/agent/service-packages', [
            'name' => 'E2E Flat Fee',
            'price' => 0,
            'is_active' => true,
            'assigned_zips' => ['33139'],
            'listing_process_id' => $process->id,
        ])->assertCreated()->json('id');

        // 2. Seller finds the plan for their ZIP and creates the listing with it
        $listingId = $this->actingAs($this->seller)->postJson('/api/listings', [
            'zip' => '33139',
            'packageId' => $packageId,
            'address' => '1 Ocean Drive',
            'city' => 'Miami',
            'state' => 'FL',
        ])->assertCreated()->json('data.id');
        $listing = Listing::findOrFail($listingId);
        $this->assertNotNull($listing->listing_process_id, 'listing must get a listing process');
        $this->assertGreaterThan(0, $listing->steps_total);

        // 3. Seller answers every step of the workflow
        $answers = [
            'step_property_type_address' => [
                'property_sub_type' => '1', 'street_address' => '1 Ocean Drive', 'city' => 'Miami', 'zip' => '33139', 'state' => 'FL',
                'owner_type' => 'private', 'owner_type_name' => 'Sam Seller', 'owner_type_email' => 'sam.seller@example.com',
            ],
            'step_property_information' => ['list_price' => 400000, 'year_built' => 1965],
        ];
        $steps = $this->actingAs($this->seller)->getJson("/api/listings/{$listingId}/workflow")->assertOk()->json('data.steps')
            ?? collect($listing->workflow_snapshot)->all();
        $this->assertNotEmpty($steps, 'workflow must have steps');
        foreach ($steps as $step) {
            $this->actingAs($this->seller)
                ->postJson("/api/listings/{$listingId}/steps/{$step['id']}", ['values' => $answers[$step['id']] ?? ['answered' => 'yes']])
                ->assertOk();
        }

        // 4. The documents for this listing were generated; seller signs them with EunoDoc
        $this->assertGreaterThan(0, $listing->documents()->count(), 'documents must be generated from the answers');
        $signing = $this->actingAs($this->seller)->postJson("/api/listings/{$listingId}/euno-sign/initiate")->assertOk();
        $this->assertNotEmpty($signing->json('data.signing_url'));
        $this->assertSame('sam.seller@example.com', $signing->json('data.signers.0.email'));

        // The seller is told to sign after logging in
        $this->actingAs($this->seller)->getJson('/api/me/pending-signatures')->assertOk()->assertJsonPath('data.count', 1);

        $status = $this->actingAs($this->seller)->getJson("/api/listings/{$listingId}/euno-sign/status")->assertOk();
        $status->assertJsonPath('data.is_completed', true)->assertJsonPath('data.has_signed_pdf', true);
        $agreement = ListingEunoAgreement::where('listing_id', $listingId)->whereNull('listing_offer_id')->firstOrFail();
        $this->assertStringStartsWith("listings/{$listingId}/signed/", $agreement->signed_file_path);
        Storage::disk('local')->assertExists($agreement->signed_file_path);
        foreach (array_keys($agreement->source_file_paths) as $docId) {
            $this->assertSame('signed', $listing->documents()->find($docId)->status, "document {$docId} must be marked signed");
        }
        $this->actingAs($this->seller)->getJson('/api/me/pending-signatures')->assertJsonPath('data.count', 0);

        // 5. Seller publishes; it shows on the public site
        $this->actingAs($this->seller)->postJson("/api/listings/{$listingId}/submit")->assertOk();
        $this->assertSame('published', $listing->fresh()->status);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/public/listings/{$listingId}")->assertOk();

        // 6. Buyer sends an offer
        $offerId = $this->actingAs($this->buyer)->postJson("/api/listings/{$listingId}/offers", [
            'offer_amount' => 395000,
            'earnest_money_deposit' => 5000,
            'payment_method' => 'cash',
            'closing_date' => now()->addDays(30)->toDateString(),
        ])->assertCreated()->json('offer.id');
        $this->assertNotNull($offerId);

        // Seller sees it under Received, buyer under Sent
        $this->actingAs($this->seller)->getJson('/api/offers/involvement?involvement=received')->assertJsonPath('counts.received', 1);
        $this->actingAs($this->buyer)->getJson('/api/offers/involvement?involvement=sent')->assertJsonPath('counts.sent', 1);

        // 7. Seller accepts
        $this->actingAs($this->seller)->postJson("/api/offers/{$offerId}/accept")->assertOk();
        $this->assertSame(ListingOffer::STATUS_ACCEPTED, ListingOffer::find($offerId)->status);
        $this->assertContains($listing->fresh()->status, ['under_contract', 'ccs', 'contingent_continue_to_show']);

        // 8. Purchase agreement signing: started by the seller, completed via status sync
        $pa = $this->actingAs($this->seller)->postJson("/api/offers/{$offerId}/euno-sign/initiate")->assertOk();
        $this->assertNotEmpty($pa->json('data.signing_url'));

        // A stranger can't see the agreement or its signing link
        $stranger = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $this->actingAs($stranger)->getJson("/api/offers/{$offerId}/euno-sign/status")->assertForbidden();

        $this->actingAs($this->buyer)->getJson("/api/offers/{$offerId}/euno-sign/status")
            ->assertOk()->assertJsonPath('data.is_completed', true);
        $paAgreement = ListingEunoAgreement::where('listing_offer_id', $offerId)->firstOrFail();
        $this->assertNotNull($paAgreement->signed_file_path);
        Storage::disk('local')->assertExists($paAgreement->signed_file_path);
    }
}
