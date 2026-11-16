<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingFastEditTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test-token')->plainTextToken;
    }

    public function test_owner_can_update_listing_price_and_audit_history(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->for($user)->create([
            'status' => 'published',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/listings/{$listing->id}/price", [
                'price' => 385000,
                'reason' => 'Summer market adjustment',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.price', 385000);
        $response->assertJsonPath('message', 'Listing price updated successfully.');

        // Verify statusHistories was created
        $this->assertDatabaseHas('listing_status_histories', [
            'listing_id' => $listing->id,
            'to_status' => 'published',
            'actor_id' => $user->id,
        ]);
    }

    public function test_owner_can_update_listing_remarks_and_title(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->for($user)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson("/api/listings/{$listing->id}/remarks", [
                'listing_title' => 'Stunning Colonial Home',
                'remarks' => 'Beautifully restored historic estate with hardwood floors.',
                'directions' => 'Main St to Oak Avenue.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.listingTitle', 'Stunning Colonial Home');
        $response->assertJsonPath('data.remarks', 'Beautifully restored historic estate with hardwood floors.');
        $response->assertJsonPath('data.directions', 'Main St to Oak Avenue.');
    }

    public function test_owner_can_update_buyer_agent_compensation_and_rebate(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->for($user)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson("/api/listings/{$listing->id}/compensation", [
                'compensation' => 2.5,
                'compensation_type' => 1,
                'direct_buyer_rebate' => 1500,
                'seller_concessions' => 2500,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.compensation', 2.5);
        $response->assertJsonPath('data.compensationType', 1);
        $response->assertJsonPath('data.directBuyerRebate', 1500);
        $response->assertJsonPath('data.sellerConcessions', 2500);
    }

    public function test_unauthorized_user_cannot_update_listing_price(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $listing = Listing::factory()->for($owner)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger))
            ->postJson("/api/listings/{$listing->id}/price", [
                'price' => 200000,
            ]);

        $response->assertForbidden();
    }
}
