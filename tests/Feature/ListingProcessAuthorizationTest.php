<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AgentPackage;
use App\Models\ListingProcess;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingProcessAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->agent1 = User::factory()->create(['role' => UserRole::Agent]);
        $this->agent2 = User::factory()->create(['role' => UserRole::Agent]);
        $this->seller = User::factory()->create(['role' => UserRole::Seller]);
        $this->buyer = User::factory()->create(['role' => UserRole::Buyer]);

        $package = Package::factory()->create(['max_listing_processes' => 10]);
        AgentPackage::factory()->create(['user_id' => $this->agent1->id, 'package_id' => $package->id, 'status' => 'active']);
        AgentPackage::factory()->create(['user_id' => $this->agent2->id, 'package_id' => $package->id, 'status' => 'active']);

        $this->adminProcess = ListingProcess::factory()->create(['agent_id' => null, 'type' => 'default']);
        $this->agent1Process = ListingProcess::factory()->create(['agent_id' => $this->agent1->id, 'type' => 'custom']);
    }

    public function test_admin_creates_process_allowed()
    {
        $response = $this->actingAs($this->admin)->postJson('/api/listing-processes', [
            'name' => 'Admin Process',
        ]);
        $response->assertStatus(201);
        $this->assertNull($response->json('agent_id'));
    }

    public function test_admin_updates_process_allowed()
    {
        $response = $this->actingAs($this->admin)->putJson('/api/listing-processes/' . $this->adminProcess->id, [
            'name' => 'Updated Admin Process',
        ]);
        $response->assertStatus(200);
    }

    public function test_agent_creates_own_process_allowed()
    {
        $response = $this->actingAs($this->agent1)->postJson('/api/listing-processes', [
            'name' => 'Agent Process',
        ]);
        $response->assertStatus(201);
        $this->assertEquals($this->agent1->id, $response->json('agent_id'));
    }

    public function test_agent_updates_own_process_allowed()
    {
        $response = $this->actingAs($this->agent1)->putJson('/api/listing-processes/' . $this->agent1Process->id, [
            'name' => 'Updated Agent Process',
        ]);
        $response->assertStatus(200);
    }

    public function test_agent_updates_another_agent_process_forbidden()
    {
        $response = $this->actingAs($this->agent2)->putJson('/api/listing-processes/' . $this->agent1Process->id, [
            'name' => 'Hacked Process',
        ]);
        $response->assertStatus(403);
    }

    public function test_agent_updates_admin_process_forbidden()
    {
        $response = $this->actingAs($this->agent1)->putJson('/api/listing-processes/' . $this->adminProcess->id, [
            'name' => 'Hacked Admin Process',
        ]);
        $response->assertStatus(403);
    }

    public function test_seller_attempts_admin_process_api_forbidden()
    {
        $response = $this->actingAs($this->seller)->getJson('/api/listing-processes');
        $response->assertStatus(403);
    }

    public function test_buyer_attempts_admin_process_api_forbidden()
    {
        $response = $this->actingAs($this->buyer)->getJson('/api/listing-processes');
        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_unauthorized()
    {
        $response = $this->getJson('/api/listing-processes');
        $response->assertStatus(401);
    }
}
