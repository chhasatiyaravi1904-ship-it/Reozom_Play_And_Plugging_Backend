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

    private function subscribeAgent(User $agent, $expiresAt = null): AgentPackage
    {
        $sysPackage = \App\Models\Package::firstOrCreate(
            ['slug' => 'agent-pro'],
            ['name' => 'Agent Pro', 'role_name' => 'agent-pro', 'price' => 50, 'duration_days' => 30, 'is_active' => true]
        );

        return AgentPackage::create([
            'user_id' => $agent->id,
            'package_id' => $sysPackage->id,
            'started_at' => now(),
            'expires_at' => $expiresAt ?? now()->addDays(30),
        ]);
    }

    public function test_it_validates_scenario_a_valid_flow()
    {
        $seller = clone User::factory()->create(['role' => 'buyer']); // or seller
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        
        // Agent subscription valid
        $this->subscribeAgent($agent);
        
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

    public function test_it_validates_scenario_b_package_tampering()
    {
        $seller = User::factory()->create(['role' => 'buyer']);
        
        // Package B does NOT serve 12345
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $this->subscribeAgent($agent);
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

    public function test_it_validates_scenario_c_missing_process()
    {
        $seller = User::factory()->create(['role' => 'buyer']);
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $this->subscribeAgent($agent);
        
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

    public function test_it_validates_scenario_d_agent_subscription_expired()
    {
        $seller = User::factory()->create(['role' => 'buyer']);
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        
        // Subscription EXPIRED
        $this->subscribeAgent($agent, now()->subDays(1));
        
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

    public function test_it_persists_and_retrieves_disclosure_answers()
    {
        $seller = User::factory()->create(['role' => 'seller']);
        $otherSeller = User::factory()->create(['role' => 'seller']);

        $listing = \App\Models\Listing::factory()->for($seller)->create();

        // Save disclosures using step_id => values map
        $postData = [
            'answers' => [
                'step_disclosures_statutory' => [
                    'lead_paint' => 'no',
                    'roof_leaks' => 'no',
                ],
            ]
        ];

        // Other seller cannot save
        $this->actingAs($otherSeller)
            ->postJson("/api/listings/{$listing->id}/disclosures", $postData)
            ->assertForbidden();

        // Owner seller can save
        $response = $this->actingAs($seller)
            ->postJson("/api/listings/{$listing->id}/disclosures", $postData);

        $response->assertOk();
        $this->assertDatabaseHas('listing_answers', [
            'listing_id' => $listing->id,
            'step_id' => 'step_disclosures_statutory',
        ]);

        // Retrieve disclosures
        $getResponse = $this->actingAs($seller)
            ->getJson("/api/listings/{$listing->id}/disclosures");

        $getResponse->assertOk();
        $getResponse->assertJsonPath('data.answers.step_disclosures_statutory.lead_paint', 'no');
    }
}


