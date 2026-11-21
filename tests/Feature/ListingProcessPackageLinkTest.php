<?php

namespace Tests\Feature;

use App\Models\AgentPackage;
use App\Models\ListingProcess;
use App\Models\Package;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * One listing process can serve several service packages; each package uses one process,
 * and agents only link their own packages.
 */
class ListingProcessPackageLinkTest extends TestCase
{
    use RefreshDatabase;

    protected User $agent;
    protected User $otherAgent;
    protected ServicePackage $package;
    protected ServicePackage $secondPackage;
    protected ServicePackage $otherPackage;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Package::create([
            'name' => 'Agent Pro',
            'slug' => 'agent-pro',
            'role_name' => 'agent-pro',
            'price' => 50,
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $this->otherAgent = User::factory()->create(['role' => 'agent', 'is_active' => true]);

        foreach ([$this->agent, $this->otherAgent] as $agent) {
            AgentPackage::create([
                'user_id' => $agent->id,
                'package_id' => $plan->id,
                'started_at' => now(),
                'expires_at' => now()->addDays(30),
            ]);
        }

        $this->package = ServicePackage::create(['agent_id' => $this->agent->id, 'name' => 'Mine', 'price' => 100, 'is_active' => true]);
        $this->secondPackage = ServicePackage::create(['agent_id' => $this->agent->id, 'name' => 'Mine too', 'price' => 200, 'is_active' => true]);
        $this->otherPackage = ServicePackage::create(['agent_id' => $this->otherAgent->id, 'name' => 'Theirs', 'price' => 100, 'is_active' => true]);
    }

    private function agentProcess(array $attrs = []): ListingProcess
    {
        return ListingProcess::create(array_merge([
            'name' => 'Agent Process',
            'type' => 'custom',
            'status' => 'active',
            'agent_id' => $this->agent->id,
            'config' => [],
        ], $attrs));
    }

    private function packagesOf(ListingProcess|int $process): array
    {
        $id = $process instanceof ListingProcess ? $process->id : $process;

        return ServicePackage::where('listing_process_id', $id)->orderBy('id')->pluck('id')->all();
    }

    public function test_one_process_can_serve_several_packages(): void
    {
        $response = $this->actingAs($this->agent)
            ->postJson('/api/listing-processes', [
                'name' => 'Shared',
                'type' => 'custom',
                'service_package_ids' => [$this->package->id, $this->secondPackage->id],
            ])
            ->assertCreated()
            ->assertJsonPath('service_package_ids', [$this->package->id, $this->secondPackage->id]);

        $this->assertSame([$this->package->id, $this->secondPackage->id], $this->packagesOf($response->json('id')));

        $this->actingAs($this->agent)
            ->getJson('/api/listing-processes')
            ->assertOk()
            ->assertJsonFragment(['id' => $response->json('id'), 'service_package_ids' => [$this->package->id, $this->secondPackage->id]]);
    }

    public function test_agent_cannot_link_another_agents_package(): void
    {
        $this->actingAs($this->agent)
            ->postJson('/api/listing-processes', [
                'name' => 'New',
                'type' => 'custom',
                'service_package_ids' => [$this->package->id, $this->otherPackage->id],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_package_ids');
        $this->assertSame(0, ListingProcess::where('name', 'New')->count());

        $process = $this->agentProcess();
        $this->actingAs($this->agent)
            ->putJson("/api/listing-processes/{$process->id}", ['service_package_ids' => [$this->otherPackage->id]])
            ->assertStatus(422);

        $this->assertNull($this->otherPackage->fresh()->listing_process_id);
    }

    public function test_picking_a_package_moves_it_from_its_old_process(): void
    {
        $old = $this->agentProcess(['name' => 'Old']);
        $this->package->update(['listing_process_id' => $old->id]);
        $this->secondPackage->update(['listing_process_id' => $old->id]);

        $new = $this->agentProcess(['name' => 'New']);
        $this->actingAs($this->agent)
            ->putJson("/api/listing-processes/{$new->id}", ['service_package_ids' => [$this->package->id]])
            ->assertOk()
            ->assertJsonPath('service_package_ids', [$this->package->id]);

        $this->assertSame([$this->package->id], $this->packagesOf($new));
        $this->assertSame([$this->secondPackage->id], $this->packagesOf($old));
    }

    public function test_unticking_a_package_unlinks_it_and_other_saves_leave_packages_alone(): void
    {
        $process = $this->agentProcess();
        $this->package->update(['listing_process_id' => $process->id]);
        $this->secondPackage->update(['listing_process_id' => $process->id]);

        // A save without the list (e.g. renaming from the builder) keeps the packages.
        $this->actingAs($this->agent)
            ->putJson("/api/listing-processes/{$process->id}", ['name' => 'Renamed'])
            ->assertOk();
        $this->assertSame([$this->package->id, $this->secondPackage->id], $this->packagesOf($process));

        $this->actingAs($this->agent)
            ->putJson("/api/listing-processes/{$process->id}", ['service_package_ids' => [$this->secondPackage->id]])
            ->assertOk();
        $this->assertSame([$this->secondPackage->id], $this->packagesOf($process));
        $this->assertNull($this->package->fresh()->listing_process_id);

        $this->actingAs($this->agent)
            ->putJson("/api/listing-processes/{$process->id}", ['service_package_ids' => []])
            ->assertOk();
        $this->assertSame([], $this->packagesOf($process));
    }

    public function test_full_seed_gives_every_package_its_process(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        // Only the two original Reozom processes exist after a full seed...
        $seeded = ListingProcess::pluck('name')->sort()->values()->all();
        $this->assertSame(['Florida Residential Listing Process', 'Michigan Residential Listing Process'], $seeded);

        // ...and every seeded package (not the ones this test made in setUp) is linked to one of them.
        $mine = [$this->package->id, $this->secondPackage->id, $this->otherPackage->id];
        $this->assertSame(0, \App\Models\ServicePackage::whereNotIn('id', $mine)->whereNull('listing_process_id')->count());
        $this->assertNotEmpty($this->packagesOf(ListingProcess::where('name', 'Michigan Residential Listing Process')->firstOrFail()));
        $this->assertNotEmpty($this->packagesOf(ListingProcess::where('name', 'Florida Residential Listing Process')->firstOrFail()));
    }

    public function test_migration_moves_existing_links_to_the_packages(): void
    {
        $migration = require database_path('migrations/2026_11_17_000002_move_process_package_link_to_service_packages.php');

        $process = $this->agentProcess();
        $this->package->update(['listing_process_id' => $process->id]);

        // Back to the old shape: the link is stored on the process.
        $migration->down();
        $this->assertTrue(Schema::hasColumn('listing_processes', 'service_package_id'));
        $this->assertSame($this->package->id, (int) DB::table('listing_processes')->where('id', $process->id)->value('service_package_id'));

        // And forward again: the link moves back to the package.
        $migration->up();
        $this->assertFalse(Schema::hasColumn('listing_processes', 'service_package_id'));
        $this->assertSame($process->id, (int) DB::table('service_packages')->where('id', $this->package->id)->value('listing_process_id'));
    }
}
