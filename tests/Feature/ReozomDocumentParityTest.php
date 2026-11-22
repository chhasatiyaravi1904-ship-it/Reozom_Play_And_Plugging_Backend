<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DocumentTemplate;
use App\Models\Listing;
use App\Models\ListingEunoAgreement;
use App\Models\ListingOffer;
use App\Models\ListingProcess;
use App\Models\ServicePackage;
use App\Models\User;
use App\Notifications\LeadPamphletNotification;
use App\Services\DisclosureService;
use Database\Seeders\DocumentTemplateSeeder;
use Database\Seeders\FloridaListingProcessSeeder;
use Database\Seeders\ReozomStatutoryDisclosuresSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Listing and offer documents created like the original Reozom: template set, documents before and
 * after payment, the EPA lead pamphlet, and the purchase agreement addendum.
 */
class ReozomDocumentParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['euno.mock_mode' => true]);
        Storage::fake('local');
    }

    // --- A: template set ---

    public function test_template_set_matches_reozom(): void
    {
        $this->seed(DocumentTemplateSeeder::class);
        $notTrustOwner = ['owner_type' => 'trust', 'owner_type_trust_type' => 'non_owner_occupied'];

        $this->assertFalse(DocumentTemplate::where('code', 'mi_res_grar')->value('is_active'), 'GRAR is never created in Reozom');

        // Lead for every residential listing, whatever the year built; not for non-owner-occupied trusts
        foreach (['mi_res_lead', 'fl_res_lead'] as $code) {
            $rules = DocumentTemplate::where('code', $code)->firstOrFail()->rules;
            $this->assertTrue(ListingProcess::conditionMatches($rules, ['year_built' => 2005]), "{$code} for a 2005 home");
            $this->assertFalse(ListingProcess::conditionMatches($rules, $notTrustOwner), "{$code} not for trusts");
        }

        // MI vacant land trust keeps only the listing agreement
        $servicesRules = DocumentTemplate::where('code', 'mi_land_services_provided')->firstOrFail()->rules;
        $this->assertFalse(ListingProcess::conditionMatches($servicesRules, $notTrustOwner));
    }

    // --- E: documents before / after payment ---

    public function test_unpaid_listing_gets_only_disclosure_documents_and_the_full_set_once_paid(): void
    {
        $this->seed(ReozomStatutoryDisclosuresSeeder::class);
        $this->seed(DocumentTemplateSeeder::class);
        $agent = User::factory()->create(['role' => UserRole::Agent, 'email' => 'agent@example.com']);
        $this->seed(FloridaListingProcessSeeder::class);

        $process = ListingProcess::where('name', 'Florida Residential Listing Process')->firstOrFail();
        $package = ServicePackage::create(['agent_id' => $agent->id, 'name' => 'Full MLS', 'price' => 295.00, 'is_active' => true]);
        $listing = Listing::factory()->create([
            'user_id' => User::factory()->create(['role' => UserRole::SellerBuyer])->id,
            'state' => 'FL', 'zip' => '33139',
            'service_package_id' => $package->id,
            'listing_process_id' => $process->id,
            'workflow_snapshot' => $process->config,
        ]);
        $listing->answers()->create(['step_id' => 'step_property_type_address', 'values' => ['property_sub_type' => '1']]);

        $codes = fn () => $listing->documents()->whereNotNull('document_template_id')->pluck('type')->all();

        app(DisclosureService::class)->generateListingDocuments($listing);
        $this->assertContains('fl_res_sf_disclosure', $codes());
        $this->assertContains('fl_res_lead', $codes());
        $this->assertNotContains('fl_res_listing_agreement', $codes(), 'Agreements wait for payment');
        $this->assertNotContains('fl_res_services_provided', $codes());

        // Nothing left to pay (free package): the full set
        $package->update(['price' => null]);
        app(DisclosureService::class)->generateListingDocuments($listing->fresh());
        $this->assertContains('fl_res_listing_agreement', $codes());
        $this->assertContains('fl_res_services_provided', $codes());
    }

    // --- B: lead pamphlet on the listing ---

    private function residentialListing(User $owner, int $yearBuilt, string $state = 'FL'): Listing
    {
        $listing = Listing::factory()->create(['user_id' => $owner->id, 'state' => $state, 'status' => 'in_progress']);
        $listing->answers()->create(['step_id' => 'step_property_information', 'values' => ['year_built' => $yearBuilt]]);

        return $listing;
    }

    public function test_publishing_a_pre_1978_home_adds_the_lead_pamphlet_once(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $old = $this->residentialListing($seller, 1965);
        $new = $this->residentialListing($seller, 1990);

        $old->publish();
        $old->publish();
        $new->publish();

        $pamphlets = $old->documents()->where('type', DisclosureService::LEAD_PAMPHLET_TYPE)->get();
        $this->assertCount(1, $pamphlets);
        Storage::disk('local')->assertExists($pamphlets->first()->path);
        $this->assertSame(0, $new->documents()->where('type', DisclosureService::LEAD_PAMPHLET_TYPE)->count());
    }

    public function test_agent_and_out_of_state_listings_get_no_lead_pamphlet(): void
    {
        $agentListing = $this->residentialListing(User::factory()->create(['role' => UserRole::Agent]), 1965);
        $texas = $this->residentialListing(User::factory()->create(['role' => UserRole::SellerBuyer]), 1965, 'TX');

        $agentListing->publish();
        $texas->publish();

        $this->assertSame(0, $agentListing->documents()->where('type', DisclosureService::LEAD_PAMPHLET_TYPE)->count());
        $this->assertSame(0, $texas->documents()->where('type', DisclosureService::LEAD_PAMPHLET_TYPE)->count());
    }

    // --- C: lead pamphlet emailed with an offer ---

    public function test_offer_on_a_pre_1978_home_emails_the_lead_pamphlet_to_buyer_and_purchasers(): void
    {
        Notification::fake();
        $seller = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $buyer = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $listing = $this->residentialListing($seller, 1965);
        $listing->update(['status' => 'published']);

        $this->actingAs($buyer)->postJson("/api/listings/{$listing->id}/offers", [
            'offer_amount' => 450000,
            'earnest_money_deposit' => 10000,
            'payment_method' => 'conventional',
            'closing_date' => now()->addDays(30)->toDateString(),
            'occupancy_type' => 'at_closing',
            'purchasers' => [['first_name' => 'Pat', 'last_name' => 'Purchaser', 'email' => 'pat@example.com']],
        ])->assertCreated();

        Notification::assertSentTo($buyer, LeadPamphletNotification::class);
        Notification::assertSentOnDemand(LeadPamphletNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'pat@example.com');
    }

    public function test_offer_on_a_newer_home_sends_no_pamphlet(): void
    {
        Notification::fake();
        $seller = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $buyer = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $listing = $this->residentialListing($seller, 1995);
        $listing->update(['status' => 'published']);

        $this->actingAs($buyer)->postJson("/api/listings/{$listing->id}/offers", [
            'offer_amount' => 450000,
            'earnest_money_deposit' => 10000,
            'payment_method' => 'conventional',
            'closing_date' => now()->addDays(30)->toDateString(),
            'occupancy_type' => 'at_closing',
        ])->assertCreated();

        Notification::assertNothingSent();
    }

    // --- D: purchase agreement addendum ---

    private function acceptedOffer(User $seller, User $buyer): ListingOffer
    {
        $listing = Listing::factory()->create(['user_id' => $seller->id]);

        return ListingOffer::create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'offer_amount' => 450000,
            'closing_date' => now()->addDays(30),
            'status' => 'accepted',
        ]);
    }

    public function test_party_adds_an_addendum_that_every_signer_signs(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SellerBuyer, 'email' => 'owner@example.com']);
        $buyer = User::factory()->create(['role' => UserRole::SellerBuyer, 'email' => 'buyer@example.com']);
        $offer = $this->acceptedOffer($seller, $buyer);
        // Mock EunoDoc reports every agreement as completed; keep the addendum waiting for signatures
        $this->fakeEuno(fn ($mock) => $mock->shouldReceive('getAgreementStatus')->andReturn('inprogress'));

        $this->actingAs($seller)->postJson("/api/offers/{$offer->id}/addendums", ['description' => 'Closing moves to June 30.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'inprogress');

        $agreement = ListingEunoAgreement::where('listing_offer_id', $offer->id)->where('document_type', 'addendum')->firstOrFail();
        $this->assertSame(['buyer@example.com', 'owner@example.com'], array_column($agreement->signers, 'email'));

        // The buyer sees it with their own signing link
        $this->actingAs($buyer)->getJson("/api/offers/{$offer->id}/addendums")
            ->assertOk()
            ->assertJsonPath('data.0.description', 'Closing moves to June 30.')
            ->assertJsonPath('data.0.signing_url', fn ($url) => is_string($url) && str_ends_with($url, '/'.$agreement->signers[0]['recipient_id']));

        // The addendum agreement is never mistaken for the purchase agreement
        $this->actingAs($buyer)->postJson("/api/offers/{$offer->id}/euno-sign/initiate")->assertOk();
        $this->assertSame(1, ListingEunoAgreement::where('listing_offer_id', $offer->id)->where('document_type', 'purchase_agreement')->count());
        $this->assertSame('inprogress', $agreement->fresh()->status);
    }

    public function test_addendum_rules(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $buyer = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $stranger = User::factory()->create(['role' => UserRole::SellerBuyer]);
        $offer = $this->acceptedOffer($seller, $buyer);

        $this->actingAs($stranger)->postJson("/api/offers/{$offer->id}/addendums", ['description' => 'x'])->assertForbidden();
        $this->actingAs($stranger)->getJson("/api/offers/{$offer->id}/addendums")->assertForbidden();
        $this->actingAs($seller)->postJson("/api/offers/{$offer->id}/addendums", ['description' => ''])->assertStatus(422);
        $this->actingAs($seller)->postJson("/api/offers/{$offer->id}/addendums", ['description' => str_repeat('a', 2001)])->assertStatus(422);

        $offer->update(['status' => ListingOffer::STATUS_SUBMITTED]);
        $this->actingAs($seller)->postJson("/api/offers/{$offer->id}/addendums", ['description' => 'Too early'])->assertStatus(422);
        $this->assertDatabaseCount('offer_addendums', 0);
    }
}
