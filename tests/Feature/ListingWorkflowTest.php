<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\ZipCode;
use App\Models\ServicePackage;
use App\Models\ListingProcess;
use App\Models\AgentPackage;

class ListingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /** @test */
    public function it_validates_scenario_a_valid_flow()
    {
        $seller = clone User::factory()->create(['role' => 'buyer']); // or seller
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        
        // Agent subscription valid
        AgentPackage::factory()->create([
            'user_id' => $agent->id,
            'expires_at' => now()->addDays(30)
        ]);
        
        $zip = ZipCode::factory()->create(['code' => '12345', 'is_active' => true]);
        
        $package = ServicePackage::factory()->create([
            'agent_id' => $agent->id,
            'is_active' => true
        ]);
        $package->zipCodes()->attach($zip->id);
        
        $process = ListingProcess::factory()->create([
            'agent_id' => $agent->id,
            'service_package_id' => $package->id,
            'status' => 'active',
            'config' => [['id' => 'step1'], ['id' => 'step2']]
        ]);

        $response = $this->actingAs($seller)->postJson('/api/listings', [
            'zip' => '12345',
            'packageId' => $package->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('listings', [
            'user_id' => $seller->id,
            'zip' => '12345',
            'service_package_id' => $package->id,
            'listing_process_id' => $process->id,
            'steps_total' => 2,
            'steps_completed' => 0
        ]);
    }

    /** @test */
    public function it_validates_scenario_b_package_tampering()
    {
        $seller = User::factory()->create(['role' => 'buyer']);
        
        // Package B does NOT serve 12345
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        AgentPackage::factory()->create(['user_id' => $agent->id, 'expires_at' => now()->addDays(30)]);
        $zip = ZipCode::factory()->create(['code' => '12345', 'is_active' => true]);
        
        $packageB = ServicePackage::factory()->create([
            'agent_id' => $agent->id,
            'is_active' => true
        ]);
        // Package B is NOT attached to the zip code!

        $response = $this->actingAs($seller)->postJson('/api/listings', [
            'zip' => '12345',
            'packageId' => $packageB->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('listings', [
            'user_id' => $seller->id,
        ]);
    }

    /** @test */
    public function it_validates_scenario_c_missing_process()
    {
        $seller = User::factory()->create(['role' => 'buyer']);
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        AgentPackage::factory()->create(['user_id' => $agent->id, 'expires_at' => now()->addDays(30)]);
        
        $zip = ZipCode::factory()->create(['code' => '12345', 'is_active' => true]);
        
        $package = ServicePackage::factory()->create([
            'agent_id' => $agent->id,
            'is_active' => true
        ]);
        $package->zipCodes()->attach($zip->id);
        
        // NO listing process created for this package!

        $response = $this->actingAs($seller)->postJson('/api/listings', [
            'zip' => '12345',
            'packageId' => $package->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('listings', [
            'user_id' => $seller->id,
        ]);
    }

    /** @test */
    public function it_validates_scenario_d_agent_subscription_expired()
    {
        $seller = User::factory()->create(['role' => 'buyer']);
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        
        // Subscription EXPIRED
        AgentPackage::factory()->create([
            'user_id' => $agent->id,
            'expires_at' => now()->subDays(1)
        ]);
        
        $zip = ZipCode::factory()->create(['code' => '12345', 'is_active' => true]);
        
        $package = ServicePackage::factory()->create([
            'agent_id' => $agent->id,
            'is_active' => true
        ]);
        $package->zipCodes()->attach($zip->id);

        $response = $this->actingAs($seller)->postJson('/api/listings', [
            'zip' => '12345',
            'packageId' => $package->id,
        ]);

        $response->assertStatus(422);
    }
}
