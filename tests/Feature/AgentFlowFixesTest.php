<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AgentPackage;
use App\Models\ListingProcess;
use App\Models\Package;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentFlowFixesTest extends TestCase
{
    use RefreshDatabase;

    private function registerAgent(string $packageId, string $email = 'agent@example.com')
    {
        return $this->postJson('/api/auth/register', [
            'userType' => 'agent',
            'firstName' => 'Ann',
            'lastName' => 'Agent',
            'email' => $email,
            'phone' => '555-123-4567',
            'streetAddress' => '1 Main St',
            'city' => 'Detroit',
            'state' => 'MI',
            'zip' => '48201',
            'password' => 'password',
            'passwordConfirmation' => 'password',
            'packageId' => $packageId,
        ]);
    }

    // --- Fix 1: registration must not activate a paid package ---

    public function test_registering_with_a_paid_package_does_not_activate_it(): void
    {
        $paid = Package::factory()->create(['price' => 10, 'is_active' => true]);

        $this->registerAgent($paid->id)->assertCreated();

        $agent = User::where('email', 'agent@example.com')->firstOrFail();
        $this->assertSame(0, $agent->agentPackages()->count());
        $this->assertNull($agent->currentAgentPackage()->first());
    }

    public function test_registering_with_a_free_package_activates_it(): void
    {
        $free = Package::factory()->create(['price' => 0, 'is_active' => true]);

        $this->registerAgent($free->id)->assertCreated();

        $agent = User::where('email', 'agent@example.com')->firstOrFail();
        $this->assertSame($free->id, $agent->currentAgentPackage()->first()?->package_id);
    }

    // --- Fix 2: System Defaults are not demoted ---

    public function test_admin_creating_a_system_default_keeps_other_system_defaults(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $existing = ListingProcess::factory()->create(['agent_id' => null, 'type' => 'default']);

        $this->actingAs($admin)->postJson('/api/listing-processes', [
            'name' => 'Ohio Residential',
            'type' => 'default',
        ])->assertCreated();

        $this->assertSame('default', $existing->fresh()->type);
        $this->assertSame(2, ListingProcess::whereNull('agent_id')->where('type', 'default')->count());
    }

    public function test_admin_promoting_a_process_to_default_keeps_other_system_defaults(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $existing = ListingProcess::factory()->create(['agent_id' => null, 'type' => 'default']);
        $custom = ListingProcess::factory()->create(['agent_id' => null, 'type' => 'custom']);

        $this->actingAs($admin)->putJson("/api/listing-processes/{$custom->id}", [
            'name' => $custom->name,
            'type' => 'default',
        ])->assertOk();

        $this->assertSame('default', $existing->fresh()->type);
        $this->assertSame('default', $custom->fresh()->type);
    }

    public function test_agent_new_default_still_replaces_their_own_previous_default_only(): void
    {
        $plan = Package::factory()->create(['max_listing_processes' => 10]);
        $agent = User::factory()->create(['role' => UserRole::Agent]);
        AgentPackage::factory()->create(['user_id' => $agent->id, 'package_id' => $plan->id]);
        $system = ListingProcess::factory()->create(['agent_id' => null, 'type' => 'default']);
        $ownOld = ListingProcess::factory()->create(['agent_id' => $agent->id, 'type' => 'default']);

        $this->actingAs($agent)->postJson('/api/listing-processes', [
            'name' => 'My New Default',
            'type' => 'default',
        ])->assertCreated();

        $this->assertSame('custom', $ownOld->fresh()->type);
        $this->assertSame('default', $system->fresh()->type);
    }

    // --- Subscription purchase history ---

    public function test_agent_sees_own_subscription_history_with_statuses(): void
    {
        $agent = User::factory()->create(['role' => UserRole::Agent]);
        $other = User::factory()->create(['role' => UserRole::Agent]);
        $plan = Package::factory()->create(['name' => 'Pro', 'price' => 49.99]);

        $expired = AgentPackage::factory()->create([
            'user_id' => $agent->id, 'package_id' => $plan->id,
            'started_at' => now()->subDays(60), 'expires_at' => now()->subDays(30),
        ]);
        $refunded = AgentPackage::factory()->create([
            'user_id' => $agent->id, 'package_id' => $plan->id,
            'started_at' => now()->subDays(20), 'expires_at' => now()->subDays(19),
        ]);
        $active = AgentPackage::factory()->create([
            'user_id' => $agent->id, 'package_id' => $plan->id,
            'started_at' => now()->subDay(), 'expires_at' => now()->addDays(29),
        ]);
        AgentPackage::factory()->create(['user_id' => $other->id, 'package_id' => $plan->id]);

        Payment::create([
            'type' => Payment::TYPE_AGENT_PACKAGE, 'user_id' => $agent->id, 'package_id' => $plan->id,
            'agent_package_id' => $active->id, 'amount' => 4999, 'currency' => 'usd',
            'status' => 'paid', 'stripe_session_id' => 'cs_active',
        ]);
        Payment::create([
            'type' => Payment::TYPE_AGENT_PACKAGE, 'user_id' => $agent->id, 'package_id' => $plan->id,
            'agent_package_id' => $refunded->id, 'amount' => 4999, 'currency' => 'usd',
            'status' => 'refunded', 'stripe_session_id' => 'cs_refunded',
        ]);

        $response = $this->actingAs($agent)->getJson('/api/agent/subscriptions')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame('active', $rows[$active->id]['status']);
        $this->assertSame(4999, $rows[$active->id]['amount']);
        $this->assertSame('refunded', $rows[$refunded->id]['status']);
        $this->assertSame('expired', $rows[$expired->id]['status']);
        $this->assertSame(0, $rows[$expired->id]['amount']);
        $this->assertSame('Pro', $rows[$active->id]['package']['name']);
        $this->assertSame($active->id, $response->json('data.0.id'));
    }

    public function test_subscription_history_is_not_available_to_sellers_or_guests(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SellerBuyer]);

        $this->getJson('/api/agent/subscriptions')->assertUnauthorized();
        $this->actingAs($seller)->getJson('/api/agent/subscriptions')->assertForbidden();
    }
}
