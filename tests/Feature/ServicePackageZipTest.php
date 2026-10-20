<?php

namespace Tests\Feature;

use App\Models\AgentPackage;
use App\Models\Package;
use App\Models\ServicePackage;
use App\Models\User;
use App\Models\ZipCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicePackageZipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->agent = User::factory()->create([
            'role' => 'agent',
            'is_active' => true,
        ]);

        $this->package = Package::create([
            'name' => 'Agent Pro',
            'slug' => 'agent-pro',
            'role_name' => 'agent-pro',
            'price' => 50,
            'duration_days' => 30,
            'is_active' => true,
        ]);

        AgentPackage::create([
            'user_id' => $this->agent->id,
            'package_id' => $this->package->id,
            'started_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
        
        $this->zip1 = ZipCode::factory()->create(['code' => '48301', 'is_active' => true]);
        $this->zip2 = ZipCode::factory()->create(['code' => '48302', 'is_active' => true]);
        $this->zip3 = ZipCode::factory()->create(['code' => '48303', 'is_active' => true]);
    }

    public function test_agent_can_link_and_remove_zip_codes()
    {
        $this->actingAs($this->agent);

        // One package -> multiple ZIPs
        $response = $this->postJson('/api/agent/service-packages', [
            'name' => 'Test Package 1',
            'is_active' => true,
            'assigned_zips' => ['48301', '48302'],
        ]);

        $response->assertStatus(201);
        $packageId = $response->json('id');
        
        $package = ServicePackage::find($packageId);
        $this->assertCount(2, $package->zipCodes);

        // Public Search finds the package for zip1 and zip2
        $this->getJson('/api/public/service-packages/search?zipcode=48301')->assertJsonCount(1);
        $this->getJson('/api/public/service-packages/search?zipcode=48302')->assertJsonCount(1);
        $this->getJson('/api/public/service-packages/search?zipcode=48303')->assertJsonCount(0);

        // Remove ZIP
        $this->putJson("/api/agent/service-packages/{$packageId}", [
            'assigned_zips' => ['48301'],
        ])->assertStatus(200);

        $package->refresh();
        $this->assertCount(1, $package->zipCodes);
        $this->getJson('/api/public/service-packages/search?zipcode=48301')->assertJsonCount(1);
        $this->getJson('/api/public/service-packages/search?zipcode=48302')->assertJsonCount(0);

        // Inactive package
        $this->putJson("/api/agent/service-packages/{$packageId}", [
            'is_active' => false,
        ])->assertStatus(200);

        $this->getJson('/api/public/service-packages/search?zipcode=48301')->assertJsonCount(0);
    }

    public function test_multiple_packages_same_zip()
    {
        $this->actingAs($this->agent);

        $this->postJson('/api/agent/service-packages', [
            'name' => 'Package A',
            'is_active' => true,
            'assigned_zips' => ['48301'],
        ]);

        $this->postJson('/api/agent/service-packages', [
            'name' => 'Package B',
            'is_active' => true,
            'assigned_zips' => ['48301'],
        ]);

        $this->getJson('/api/public/service-packages/search?zipcode=48301')->assertJsonCount(2);
    }
}
