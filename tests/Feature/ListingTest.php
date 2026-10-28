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

    private function createZipCode(string $code): ZipCode
    {
        $county = \App\Models\County::factory()->create();
        $city = \App\Models\City::factory()->create(['county_id' => $county->id]);

        return ZipCode::create([
            'code' => $code,
            'state_id' => $county->state_id,
            'county_id' => $county->id,
            'city_id' => $city->id,
            'is_active' => true,
        ]);
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
        $sysPackage = Package::create(['name' => 'Agent Pro', 'slug' => 'agent-pro', 'role_name' => 'agent-pro', 'price' => 50, 'duration_days' => 30, 'is_active' => true]);
        AgentPackage::create(['user_id' => $agent->id, 'package_id' => $sysPackage->id, 'started_at' => now(), 'expires_at' => now()->addDays(30)]);
        $zip = $this->createZipCode('48103');
        $servicePackage = ServicePackage::create(['agent_id' => $agent->id, 'name' => 'Test Pkg', 'is_active' => true]);
        $servicePackage->zipCodes()->attach($zip->id);

        \App\Models\ListingProcess::create([
            'name' => 'Default Process',
            'agent_id' => $agent->id,
            'service_package_id' => $servicePackage->id,
            'status' => 'active',
            'config' => [['id' => 'step1', 'name' => 'Step 1'], ['id' => 'step2', 'name' => 'Step 2']],
        ]);

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
        $sysPackage = Package::create(['name' => 'Agent Pro', 'slug' => 'agent-pro', 'role_name' => 'agent-pro', 'price' => 50, 'duration_days' => 30, 'is_active' => true]);
        AgentPackage::create(['user_id' => $agent->id, 'package_id' => $sysPackage->id, 'started_at' => now(), 'expires_at' => now()->addDays(30)]);
        $zip1 = $this->createZipCode('48103');
        $zip2 = $this->createZipCode('48104');
        $servicePackage = ServicePackage::create(['agent_id' => $agent->id, 'name' => 'Test Pkg', 'is_active' => true]);
        $servicePackage->zipCodes()->attach($zip1->id);

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
        $sysPackage = Package::create(['name' => 'Agent Pro', 'slug' => 'agent-pro', 'role_name' => 'agent-pro', 'price' => 50, 'duration_days' => 30, 'is_active' => true]);
        AgentPackage::create(['user_id' => $agent->id, 'package_id' => $sysPackage->id, 'started_at' => now(), 'expires_at' => now()->addDays(30)]);
        $zip = $this->createZipCode('48103');
        
        // Inactive package
        $servicePackage = ServicePackage::create(['agent_id' => $agent->id, 'name' => 'Test Pkg', 'is_active' => false]);
        $servicePackage->zipCodes()->attach($zip->id);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/listings', [
                'address' => '123 Maple Street',
                'zip' => '48103',
                'packageId' => $servicePackage->id,
            ]);
        
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The selected service package is not available for this ZIP code.');
    }

    public function test_editing_a_published_listing_unpublishes_it_for_revalidation(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->for($user)->create([
            'status' => 'published',
            'moderation_status' => 'approved',
            'city' => 'Detroit',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson("/api/listings/{$listing->id}", ['city' => 'Ann Arbor']);

        $response->assertOk();
        $listing->refresh();
        $this->assertEquals('in_progress', $listing->status);
        $this->assertEquals('edited_pending_revalidation', $listing->moderation_status);
        $this->assertEquals('Ann Arbor', $listing->city);
    }

    public function test_public_listings_endpoint_only_returns_published_listings(): void
    {
        $published = Listing::factory()->create(['status' => 'published']);
        $submitted = Listing::factory()->create(['status' => 'submitted']);
        $inProgress = Listing::factory()->create(['status' => 'in_progress']);

        $response = $this->getJson('/api/public/listings');

        $response->assertOk();
        $response->assertJsonFragment(['id' => $published->id]);
        $response->assertJsonMissing(['id' => $submitted->id]);
        $response->assertJsonMissing(['id' => $inProgress->id]);
    }

    public function test_admin_listing_moderation_requires_admin_role(): void
    {
        $seller = User::factory()->seller()->create();
        $admin = User::factory()->admin()->create();
        $listing = Listing::factory()->create(['status' => 'published']);

        // Seller forbidden
        $this->actingAs($seller)
            ->getJson('/api/admin/listings')
            ->assertForbidden();

        // Admin allowed
        $this->actingAs($admin)
            ->getJson('/api/admin/listings')
            ->assertOk();

        // Admin unpublishes
        $this->actingAs($admin)
            ->postJson("/api/admin/listings/{$listing->id}/unpublish")
            ->assertOk();

        $listing->refresh();
        $this->assertEquals('unpublished', $listing->status);

        // Admin rejects with reason
        $this->actingAs($admin)
            ->postJson("/api/admin/listings/{$listing->id}/reject", ['rejection_reason' => 'Violation of listing policies'])
            ->assertOk();

        $listing->refresh();
        $this->assertEquals('rejected', $listing->status);
        $this->assertEquals('rejected', $listing->moderation_status);
        $this->assertEquals('Violation of listing policies', $listing->rejection_reason);
    }

    public function test_image_mutation_on_published_listing_triggers_unpublishing(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $user = User::factory()->create();
        $listing = Listing::factory()->for($user)->create([
            'status' => 'published',
            'moderation_status' => 'approved',
        ]);

        $file = \Illuminate\Http\UploadedFile::fake()->image('house.jpg');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/listings/{$listing->id}/images", [
                'images' => [$file],
            ]);

        $response->assertStatus(201);
        $listing->refresh();
        $this->assertEquals('in_progress', $listing->status);
        $this->assertEquals('edited_pending_revalidation', $listing->moderation_status);
    }
}


