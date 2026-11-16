<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AgentPackage;
use App\Models\ListingProcess;
use App\Models\Package;
use App\Models\PropertyDisclosure;
use App\Models\User;
use Database\Seeders\FloridaListingProcessSeeder;
use Database\Seeders\ReozomStatutoryDisclosuresSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingProcessDisclosureStepsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $agent;

    protected User $otherAgent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReozomStatutoryDisclosuresSeeder::class);

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->agent = User::factory()->create(['role' => UserRole::Agent]);
        $this->otherAgent = User::factory()->create(['role' => UserRole::Agent]);

        $package = Package::factory()->create(['max_listing_processes' => 10]);
        AgentPackage::factory()->create(['user_id' => $this->agent->id, 'package_id' => $package->id]);
        AgentPackage::factory()->create(['user_id' => $this->otherAgent->id, 'package_id' => $package->id]);
    }

    private function disclosureSteps(array $config): array
    {
        return array_values(array_filter($config, fn ($s) => ($s['type'] ?? null) === 'disclosure'));
    }

    private function createAgentProcess(array $codes): ListingProcess
    {
        $id = $this->actingAs($this->agent)->postJson('/api/listing-processes', [
            'name' => 'Agent FL Process',
            'required_disclosures' => $codes,
        ])->assertCreated()->json('id');

        return ListingProcess::findOrFail($id);
    }

    public function test_selected_disclosures_become_steps_with_copied_questions(): void
    {
        $process = $this->createAgentProcess(['fl_residential_single_family', 'fl_residential_condo']);

        $steps = $this->disclosureSteps($process->config);
        $this->assertCount(2, $steps);
        $this->assertSame('step_property_location', $process->config[0]['id']);
        $this->assertSame('step_payment', collect($process->config)->last()['id']);

        $sf = $steps[0];
        $this->assertSame('disclosure_fl_residential_single_family', $sf['id']);
        $this->assertSame('fl_residential_single_family', $sf['disclosure_code']);
        $this->assertSame(['field' => 'property_sub_type', 'equals' => '1'], $sf['showIf']);
        $this->assertSame(ListingProcess::TRUST_NON_OWNER_SKIP_RULE, $sf['skipIf']);
        $this->assertFalse($sf['customized']);

        $master = PropertyDisclosure::where('code', 'fl_residential_single_family')->first();
        $this->assertCount(count($master->schema['sections']), $sf['sections']);
        $this->assertSame(['field' => 'property_sub_type', 'equals' => '2'], $steps[1]['showIf']);

        $this->assertSame(range(1, count($process->config)), array_column($process->config, 'order'));
    }

    public function test_settings_change_adds_and_removes_steps_and_keeps_customizations(): void
    {
        $process = $this->createAgentProcess(['fl_residential_single_family', 'lead_paint_disclosure']);

        // Agent customizes the SF disclosure in the builder (adds a question)
        $config = $process->config;
        foreach ($config as &$step) {
            if (($step['disclosure_code'] ?? null) === 'fl_residential_single_family') {
                $step['customized'] = true;
                $step['sections'][0]['fields'][] = [
                    'id' => 'agent_solar_panels', 'name' => 'agent_solar_panels',
                    'label' => 'Are solar panels owned or leased?', 'type' => 'text', 'required' => false, 'active' => true,
                ];
            }
        }
        unset($step);
        $this->actingAs($this->agent)->putJson("/api/listing-processes/{$process->id}", ['config' => $config])->assertOk();

        // Settings modal: drop lead paint, add condo
        $this->actingAs($this->agent)->putJson("/api/listing-processes/{$process->id}", [
            'required_disclosures' => ['fl_residential_single_family', 'fl_residential_condo'],
        ])->assertOk();

        $process->refresh();
        $codes = array_column($this->disclosureSteps($process->config), 'disclosure_code');
        $this->assertEqualsCanonicalizing(['fl_residential_single_family', 'fl_residential_condo'], $codes);

        $sf = collect($process->config)->firstWhere('disclosure_code', 'fl_residential_single_family');
        $this->assertTrue($sf['customized']);
        $this->assertContains('agent_solar_panels', array_column($sf['sections'][0]['fields'], 'name'));
    }

    public function test_deleting_a_disclosure_step_in_the_builder_unselects_it(): void
    {
        $process = $this->createAgentProcess(['fl_residential_single_family', 'lead_paint_disclosure']);

        $config = array_values(array_filter($process->config, fn ($s) => ($s['disclosure_code'] ?? null) !== 'lead_paint_disclosure'));
        $this->actingAs($this->agent)->putJson("/api/listing-processes/{$process->id}", ['config' => $config])->assertOk();

        $process->refresh();
        $this->assertSame(['fl_residential_single_family'], $process->required_disclosures);
        $this->assertCount(1, $this->disclosureSteps($process->config));
    }

    public function test_status_toggle_and_rename_keep_the_config(): void
    {
        $process = $this->createAgentProcess(['fl_residential_single_family']);
        $before = $process->config;

        $this->actingAs($this->agent)->putJson("/api/listing-processes/{$process->id}", ['status' => 'draft'])->assertOk();
        $this->actingAs($this->agent)->putJson("/api/listing-processes/{$process->id}", ['name' => 'Renamed'])->assertOk();

        $this->assertEquals($before, $process->refresh()->config);
    }

    public function test_duplicate_field_names_in_a_step_are_rejected(): void
    {
        $process = $this->createAgentProcess(['fl_residential_single_family']);
        $config = $process->config;
        $index = array_search('disclosure_fl_residential_single_family', array_column($config, 'id'));

        $duplicate = $config;
        $existing = $duplicate[$index]['sections'][0]['fields'][0]['name'];
        $duplicate[$index]['sections'][1]['fields'][] = ['id' => 'x2', 'name' => $existing, 'label' => 'Dup', 'type' => 'text'];
        $this->actingAs($this->agent)->putJson("/api/listing-processes/{$process->id}", ['config' => $duplicate])
            ->assertStatus(422);
    }

    public function test_agent_cannot_edit_default_or_other_agent_process(): void
    {
        $default = ListingProcess::factory()->create(['agent_id' => null, 'type' => 'default']);
        $others = ListingProcess::factory()->create(['agent_id' => $this->otherAgent->id]);

        foreach ([$default, $others] as $process) {
            $this->actingAs($this->agent)->putJson("/api/listing-processes/{$process->id}", [
                'required_disclosures' => ['fl_residential_condo'],
            ])->assertForbidden();
        }
    }

    public function test_agent_copies_default_process_and_edits_the_copy_only(): void
    {
        $this->seed(FloridaListingProcessSeeder::class);
        $default = ListingProcess::where('name', 'Florida Residential Listing Process')->firstOrFail();

        $copyId = $this->actingAs($this->agent)->postJson("/api/listing-processes/{$default->id}/copy", [
            'name' => 'My Florida Process',
        ])->assertCreated()->json('id');

        $copy = ListingProcess::findOrFail($copyId);
        $this->assertSame($this->agent->id, $copy->agent_id);
        $this->assertSame('custom', $copy->type);
        $this->assertEquals($default->config, $copy->config);

        $config = $copy->config;
        $index = array_search('disclosure_fl_residential_condo', array_column($config, 'id'));
        $config[$index]['name'] = 'Condo Disclosure (Agent Edition)';
        $this->actingAs($this->agent)->putJson("/api/listing-processes/{$copy->id}", ['config' => $config])->assertOk();

        $defaultStep = collect($default->refresh()->config)->firstWhere('id', 'disclosure_fl_residential_condo');
        $this->assertSame("Florida Condominium Seller's Property Disclosure", $defaultStep['name']);
    }

    public function test_agent_cannot_copy_another_agents_process(): void
    {
        $others = ListingProcess::factory()->create(['agent_id' => $this->otherAgent->id]);

        $this->actingAs($this->agent)->postJson("/api/listing-processes/{$others->id}/copy")->assertForbidden();
    }

    public function test_copy_respects_agent_package_limit(): void
    {
        $limited = User::factory()->create(['role' => UserRole::Agent]);
        $package = Package::factory()->create(['max_listing_processes' => 1]);
        AgentPackage::factory()->create(['user_id' => $limited->id, 'package_id' => $package->id]);
        ListingProcess::factory()->create(['agent_id' => $limited->id]);
        $default = ListingProcess::factory()->create(['agent_id' => null, 'type' => 'default']);

        $this->actingAs($limited)->postJson("/api/listing-processes/{$default->id}/copy")->assertStatus(422);
    }

    public function test_reset_restores_master_questions(): void
    {
        $process = $this->createAgentProcess(['fl_residential_single_family']);
        $config = $process->config;
        $index = array_search('disclosure_fl_residential_single_family', array_column($config, 'id'));
        $config[$index]['customized'] = true;
        $config[$index]['sections'] = [[
            'id' => 's1', 'order' => 1, 'name' => 'Only One',
            'fields' => [['id' => 'q1', 'name' => 'q1', 'label' => 'Q1', 'type' => 'text']],
        ]];
        $this->actingAs($this->agent)->putJson("/api/listing-processes/{$process->id}", ['config' => $config])->assertOk();

        $this->actingAs($this->agent)
            ->postJson("/api/listing-processes/{$process->id}/disclosure-steps/fl_residential_single_family/reset")
            ->assertOk();

        $step = collect($process->refresh()->config)->firstWhere('id', 'disclosure_fl_residential_single_family');
        $master = PropertyDisclosure::where('code', 'fl_residential_single_family')->first();
        $this->assertFalse($step['customized']);
        $this->assertCount(count($master->schema['sections']), $step['sections']);
        $this->assertSame($index + 1, $step['order']);
    }

    public function test_reset_is_forbidden_for_other_agents(): void
    {
        $process = $this->createAgentProcess(['fl_residential_single_family']);

        $this->actingAs($this->otherAgent)
            ->postJson("/api/listing-processes/{$process->id}/disclosure-steps/fl_residential_single_family/reset")
            ->assertForbidden();
    }

    public function test_state_seeders_replace_hardcoded_disclosure_steps(): void
    {
        $this->seed(FloridaListingProcessSeeder::class);

        $residential = ListingProcess::where('name', 'Florida Residential Listing Process')->firstOrFail();
        $ids = array_column($residential->config, 'id');

        $this->assertNotContains('step_disclosures_appliances', $ids);
        $this->assertNotContains('step_disclosures_property', $ids);
        $this->assertSame([
            'disclosure_fl_residential_single_family',
            'disclosure_fl_residential_condo',
            'disclosure_lead_paint_disclosure',
            'step_images_virtual_tours',
            'step_payment',
        ], array_slice($ids, -5));
    }

    public function test_master_disclosures_pass_builder_validation(): void
    {
        $codes = PropertyDisclosure::pluck('code')->all();
        $process = $this->createAgentProcess($codes);

        $this->assertCount(count($codes), $this->disclosureSteps($process->config));

        // Saving the full config back through the builder endpoint must validate
        $this->actingAs($this->agent)->putJson("/api/listing-processes/{$process->id}", ['config' => $process->config])
            ->assertOk();
    }
}
