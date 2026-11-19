<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ListingEunoAgreement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Documents waiting for your signature" pop-up shown after login (listing documents only).
 */
class PendingSignaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $coOwner;
    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        config(['euno.mock_mode' => true]);

        $this->seller = User::factory()->create(['role' => 'seller', 'email' => 'owner@example.com']);
        $this->coOwner = User::factory()->create(['role' => 'buyer', 'email' => 'Co.Owner@example.com']);
        $this->listing = Listing::create([
            'user_id' => $this->seller->id, 'status' => 'in_progress',
            'address' => '1 Main St', 'city' => 'Detroit', 'state' => 'MI', 'zip' => '48201',
        ]);
    }

    private function agreement(array $attributes = []): ListingEunoAgreement
    {
        return ListingEunoAgreement::create($attributes + [
            'listing_id' => $this->listing->id,
            'initiated_by_user_id' => $this->seller->id,
            'document_type' => 'disclosures_bundle',
            'document_name' => 'Reozom Documents',
            'agreement_id' => 'mock_abc123',
            'signing_url' => 'https://sign.example/esign/mock_abc123/r-owner',
            'status' => 'inprogress',
            'signers' => [
                ['name' => 'Owner', 'email' => 'owner@example.com', 'recipient_id' => 'r-owner'],
                ['name' => 'Co Owner', 'email' => 'co.owner@example.com', 'recipient_id' => 'r-co'],
            ],
            'source_file_paths' => [1 => 'listings/1/unsigned/a.pdf', 2 => 'listings/1/unsigned/b.pdf'],
            'sent_at' => now(),
        ]);
    }

    private function pending(User $user)
    {
        return $this->actingAs($user)->getJson('/api/me/pending-signatures')->assertOk();
    }

    public function test_each_signer_sees_the_agreement_with_their_own_link(): void
    {
        $agreement = $this->agreement();

        $owner = $this->pending($this->seller);
        $owner->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.items.0.agreement_id', $agreement->id)
            ->assertJsonPath('data.items.0.listing_id', $this->listing->id)
            ->assertJsonPath('data.items.0.listing_address', '1 Main St, Detroit, MI 48201')
            ->assertJsonPath('data.items.0.document_count', 2);
        $this->assertStringEndsWith('/esign/mock_abc123/r-owner', $owner->json('data.items.0.signing_url'));

        // Email match is case-insensitive; the co-owner gets their own recipient link
        $co = $this->pending($this->coOwner);
        $co->assertJsonPath('data.count', 1);
        $this->assertStringEndsWith('/esign/mock_abc123/r-co', $co->json('data.items.0.signing_url'));
    }

    public function test_users_who_are_not_signers_see_nothing(): void
    {
        $this->agreement();
        // Email that merely contains a signer's email must not match
        $stranger = User::factory()->create(['email' => 'xowner@example.com']);

        $this->pending($stranger)->assertJsonPath('data.count', 0)->assertJsonPath('data.items', []);
    }

    public function test_completed_canceled_and_offer_agreements_are_not_listed(): void
    {
        $this->agreement(['status' => 'completed']);
        $this->agreement(['status' => 'canceled']);
        $offer = \App\Models\ListingOffer::create([
            'listing_id' => $this->listing->id, 'buyer_id' => $this->coOwner->id,
            'status' => \App\Models\ListingOffer::STATUS_SUBMITTED, 'offer_amount' => 1000,
            'closing_date' => now()->addDays(30)->toDateString(),
        ]);
        $this->agreement(['listing_offer_id' => $offer->id]);

        $this->pending($this->seller)->assertJsonPath('data.count', 0);
    }

    public function test_requires_login(): void
    {
        $this->getJson('/api/me/pending-signatures')->assertUnauthorized();
    }
}
