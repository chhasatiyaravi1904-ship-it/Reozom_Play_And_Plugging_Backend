<?php

namespace Tests\Feature;

use App\Models\AgentPackage;
use App\Models\ListingProcess;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Create New Process" can start from one of several System Default processes.
 */
class ListingProcessBaseProcessTest extends TestCase
{
    use RefreshDatabase;

    protected User $agent;
    protected ListingProcess $michigan;
    protected ListingProcess $florida;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Package::create([
            'name' => 'Agent Pro', 'slug' => 'agent-pro', 'role_name' => 'agent-pro',
            'price' => 50, 'duration_days' => 30, 'is_active' => true,
        ]);
        $this->agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        AgentPackage::create([
            'user_id' => $this->agent->id, 'package_id' => $plan->id,
            'started_at' => now(), 'expires_at' => now()->addDays(30),
        ]);

        $this->michigan = $this->systemDefault('Michigan Residential', 'mi_step');
        $this->florida = $this->systemDefault('Florida Residential', 'fl_step');
    }

    private function systemDefault(string $name, string $stepId): ListingProcess
    {
        return ListingProcess::create([
            'name' => $name,
            'type' => 'default',
            'status' => 'active',
            'agent_id' => null,
            'required_disclosures' => [],
            'documents' => ['doc_a'],
            'config' => [
                ['id' => $stepId, 'order' => 1, 'name' => "{$name} details", 'sections' => []],
                ['id' => "{$stepId}_images", 'order' => 2, 'name' => 'Pictures & Virtual Tours', 'sections' => []],
            ],
        ]);
    }

    private function stepIds(ListingProcess $process): array
    {
        return array_column($process->fresh()->config, 'id');
    }

    public function test_new_process_copies_the_chosen_system_default(): void
    {
        $id = $this->actingAs($this->agent)
            ->postJson('/api/listing-processes', ['name' => 'Mine', 'type' => 'custom', 'base_process_id' => $this->florida->id])
            ->assertCreated()
            ->json('id');

        $process = ListingProcess::findOrFail($id);
        $this->assertContains('fl_step', $this->stepIds($process));
        $this->assertNotContains('mi_step', $this->stepIds($process));
        $this->assertSame(['doc_a'], $process->documents);
        $this->assertSame($this->agent->id, $process->agent_id);

        // The base itself is untouched and still a System Default.
        $this->assertTrue($this->florida->fresh()->isSystemDefault());
    }

    public function test_ticked_disclosures_are_added_on_top_of_the_base(): void
    {
        \App\Models\PropertyDisclosure::create([
            'title' => 'Lead Paint', 'code' => 'lead_paint_disclosure', 'category' => 'statutory',
            'is_active' => true, 'is_required' => false, 'schema' => ['sections' => []],
        ]);

        $id = $this->actingAs($this->agent)
            ->postJson('/api/listing-processes', [
                'name' => 'Mine',
                'type' => 'custom',
                'base_process_id' => $this->michigan->id,
                'required_disclosures' => ['lead_paint_disclosure'],
            ])
            ->assertCreated()
            ->json('id');

        $process = ListingProcess::findOrFail($id);
        $this->assertSame(['lead_paint_disclosure'], $process->required_disclosures);
        $this->assertContains('mi_step', $this->stepIds($process));
        $this->assertContains(ListingProcess::disclosureStepId('lead_paint_disclosure'), $this->stepIds($process));
    }

    public function test_only_an_active_system_default_can_be_the_base(): void
    {
        $agentOwn = ListingProcess::create(['name' => 'Agent own', 'type' => 'custom', 'status' => 'active', 'agent_id' => $this->agent->id, 'config' => []]);
        $systemCustom = ListingProcess::create(['name' => 'System custom', 'type' => 'custom', 'status' => 'active', 'agent_id' => null, 'config' => []]);
        $draftDefault = ListingProcess::create(['name' => 'Draft default', 'type' => 'default', 'status' => 'draft', 'agent_id' => null, 'config' => []]);

        foreach ([$agentOwn, $systemCustom, $draftDefault] as $base) {
            $this->actingAs($this->agent)
                ->postJson('/api/listing-processes', ['name' => 'Mine', 'type' => 'custom', 'base_process_id' => $base->id])
                ->assertStatus(422)
                ->assertJsonValidationErrors('base_process_id');
        }

        $this->assertSame(0, ListingProcess::where('name', 'Mine')->count());
    }

    public function test_creating_without_a_base_still_works(): void
    {
        $id = $this->actingAs($this->agent)
            ->postJson('/api/listing-processes', ['name' => 'Blank', 'type' => 'custom'])
            ->assertCreated()
            ->json('id');

        $this->assertNotContains('mi_step', $this->stepIds(ListingProcess::findOrFail($id)));
        $this->assertNotContains('fl_step', $this->stepIds(ListingProcess::findOrFail($id)));
    }
}
