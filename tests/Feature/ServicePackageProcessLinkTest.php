<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AgentPackage;
use App\Models\ListingProcess;
use App\Models\Package;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicePackageProcessLinkTest extends TestCase
{
    use RefreshDatabase;

    protected User $agent;

    protected ListingProcess $default;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = User::factory()->create(['role' => UserRole::Agent]);
        $package = Package::factory()->create(['max_listing_processes' => 10]);
        AgentPackage::factory()->create(['user_id' => $this->agent->id, 'package_id' => $package->id]);

        $this->default = ListingProcess::factory()->create([
            'name' => 'Florida Vacant Land Listing Process',
            'agent_id' => null,
            'type' => 'default',
        ]);
    }

    public function test_picking_a_system_default_links_a_new_copy_and_leaves_the_default_untouched(): void
    {
        $packageId = $this->actingAs($this->agent)->postJson('/api/agent/service-packages', [
            'name' => 'FL Land Package',
            'price' => 9000,
            'listing_process_id' => $this->default->id,
        ])->assertCreated()->json('id');

        $copy = ListingProcess::findOrFail(ServicePackage::findOrFail($packageId)->listing_process_id);
        $this->assertNotSame($this->default->id, $copy->id);
        $this->assertSame($this->agent->id, $copy->agent_id);
        $this->assertSame('custom', $copy->type);
        $this->assertSame('Florida Vacant Land Listing Process - FL Land Package', $copy->name);
        $this->assertEquals($this->default->config, $copy->config);
        $this->assertSame(0, $this->default->servicePackages()->count());
    }

    public function test_resaving_a_package_does_not_create_another_copy(): void
    {
        $packageId = $this->actingAs($this->agent)->postJson('/api/agent/service-packages', [
            'name' => 'FL Land Package',
            'listing_process_id' => $this->default->id,
        ])->assertCreated()->json('id');
        $copyId = ServicePackage::findOrFail($packageId)->listing_process_id;
        $count = ListingProcess::count();

        $this->actingAs($this->agent)->putJson("/api/agent/service-packages/{$packageId}", [
            'name' => 'FL Land Package v2',
            'listing_process_id' => $copyId,
        ])->assertOk();

        $this->assertSame($count, ListingProcess::count());
        $this->assertSame($copyId, ServicePackage::findOrFail($packageId)->listing_process_id);
    }

    public function test_agent_cannot_link_another_agents_process(): void
    {
        $other = User::factory()->create(['role' => UserRole::Agent]);
        $othersProcess = ListingProcess::factory()->create(['agent_id' => $other->id]);

        $this->actingAs($this->agent)->postJson('/api/agent/service-packages', [
            'name' => 'Sneaky',
            'listing_process_id' => $othersProcess->id,
        ])->assertStatus(422)->assertJsonValidationErrors('listing_process_id');

        $this->assertSame(0, $othersProcess->servicePackages()->count());
        $this->assertDatabaseMissing('service_packages', ['name' => 'Sneaky']);
    }

    public function test_package_limit_blocks_the_copy_and_nothing_is_saved(): void
    {
        $limited = User::factory()->create(['role' => UserRole::Agent]);
        $package = Package::factory()->create(['max_listing_processes' => 1]);
        AgentPackage::factory()->create(['user_id' => $limited->id, 'package_id' => $package->id]);
        ListingProcess::factory()->create(['agent_id' => $limited->id]);

        $this->actingAs($limited)->postJson('/api/agent/service-packages', [
            'name' => 'Over Limit',
            'listing_process_id' => $this->default->id,
        ])->assertStatus(422)->assertJsonValidationErrors('listing_process_id');

        $this->assertDatabaseMissing('service_packages', ['name' => 'Over Limit']);
        $this->assertSame(1, $limited->listingProcesses()->count());
    }

    public function test_agent_links_a_copy_of_the_system_default(): void
    {
        $copyId = $this->actingAs($this->agent)
            ->postJson("/api/listing-processes/{$this->default->id}/copy", ['name' => 'FL Land - My Package'])
            ->assertCreated()->json('id');

        $packageId = $this->actingAs($this->agent)->postJson('/api/agent/service-packages', [
            'name' => 'FL Land Package',
            'price' => 9000,
            'listing_process_id' => $copyId,
        ])->assertCreated()->json('id');

        $this->assertSame($copyId, ServicePackage::findOrFail($packageId)->listing_process_id);
        $this->assertSame(0, $this->default->servicePackages()->count());
    }
}
