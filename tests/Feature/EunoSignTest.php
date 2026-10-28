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
            'status' => 'submitted',
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
