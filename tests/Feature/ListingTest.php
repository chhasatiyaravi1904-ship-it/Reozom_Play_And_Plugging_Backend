<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\User;
use App\Models\AgentPackage;
use App\Models\Package;
use App\Models\ServicePackage;
use App\Models\ZipCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test-token')->plainTextToken;
    }

    public function test_index_only_returns_the_authenticated_users_own_listings(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Listing::factory()->for($user)->count(2)->create();
        Listing::factory()->for($otherUser)->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/listings');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_a_seller_can_create_a_listing(): void
    {
        $user = User::factory()->create();

        // Setup valid agent and package
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $sysPackage = Package::create(['name' => 'Agent Pro', 'slug' => 'agent-pro', 'price' => 50, 'duration_days' => 30, 'is_active' => true]);
        AgentPackage::create(['user_id' => $agent->id, 'package_id' => $sysPackage->id, 'started_at' => now(), 'expires_at' => now()->addDays(30)]);
        ZipCode::create(['code' => '48103', 'is_active' => true]);
        $servicePackage = ServicePackage::create(['agent_id' => $agent->id, 'name' => 'Test Pkg', 'is_active' => true]);
        $servicePackage->zipCodes()->attach(ZipCode::where('code', '48103')->first()->id);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/listings', [
                'address' => '123 Maple Street',
                'city' => 'Ann Arbor',
                'state' => 'Michigan',
                'zip' => '48103',
                'packageId' => $servicePackage->id,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.address.city', 'Ann Arbor');
        $response->assertJsonPath('data.status', 'in_progress');
        $response->assertJsonPath('data.stepsCompleted', 0);
        $response->assertJsonStructure(['data' => ['referenceCode']]);

        $this->assertDatabaseHas('listings', ['user_id' => $user->id, 'city' => 'Ann Arbor']);
    }

    public function test_a_seller_cannot_view_another_sellers_listing(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->for(User::factory())->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/listings/{$listing->id}");

        $response->assertForbidden();
    }

    public function test_a_seller_can_update_their_own_listing(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->for($user)->create(['city' => 'Detroit']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson("/api/listings/{$listing->id}", ['city' => 'Ann Arbor']);

        $response->assertOk();
        $response->assertJsonPath('data.address.city', 'Ann Arbor');
    }

    public function test_a_seller_cannot_update_another_sellers_listing(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->for(User::factory())->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson("/api/listings/{$listing->id}", ['city' => 'Ann Arbor']);

        $response->assertForbidden();
    }

    public function test_submitting_a_listing_marks_all_steps_complete(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->for($user)->create(['steps_total' => 4, 'steps_completed' => 1]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/listings/{$listing->id}/submit");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'submitted');
        $response->assertJsonPath('data.stepsCompleted', 4);
        $response->assertJsonPath('data.progressPercent', 100);
    }

    public function test_listings_endpoints_require_authentication(): void
    {
        $this->getJson('/api/listings')->assertUnauthorized();
    }

    public function test_a_seller_cannot_create_a_listing_with_invalid_package_zip_combination(): void
    {
        $user = User::factory()->create();

        // Setup valid agent and package for a specific ZIP
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $sysPackage = Package::create(['name' => 'Agent Pro', 'slug' => 'agent-pro', 'price' => 50, 'duration_days' => 30, 'is_active' => true]);
        AgentPackage::create(['user_id' => $agent->id, 'package_id' => $sysPackage->id, 'started_at' => now(), 'expires_at' => now()->addDays(30)]);
        ZipCode::create(['code' => '48103', 'is_active' => true]);
        ZipCode::create(['code' => '48104', 'is_active' => true]);
        $servicePackage = ServicePackage::create(['agent_id' => $agent->id, 'name' => 'Test Pkg', 'is_active' => true]);
        $servicePackage->zipCodes()->attach(ZipCode::where('code', '48103')->first()->id);

        // Try creating listing for unassigned ZIP
        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/listings', [
                'address' => '123 Maple Street',
                'zip' => '48104',
                'packageId' => $servicePackage->id,
            ]);
        
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The selected service package is not available for this ZIP code.');
    }

    public function test_a_seller_cannot_create_a_listing_with_inactive_package(): void
    {
        $user = User::factory()->create();

        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $sysPackage = Package::create(['name' => 'Agent Pro', 'slug' => 'agent-pro', 'price' => 50, 'duration_days' => 30, 'is_active' => true]);
        AgentPackage::create(['user_id' => $agent->id, 'package_id' => $sysPackage->id, 'started_at' => now(), 'expires_at' => now()->addDays(30)]);
        ZipCode::create(['code' => '48103', 'is_active' => true]);
        
        // Inactive package
        $servicePackage = ServicePackage::create(['agent_id' => $agent->id, 'name' => 'Test Pkg', 'is_active' => false]);
        $servicePackage->zipCodes()->attach(ZipCode::where('code', '48103')->first()->id);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/listings', [
                'address' => '123 Maple Street',
                'zip' => '48103',
                'packageId' => $servicePackage->id,
            ]);
        
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The selected service package is not available for this ZIP code.');
    }
}
