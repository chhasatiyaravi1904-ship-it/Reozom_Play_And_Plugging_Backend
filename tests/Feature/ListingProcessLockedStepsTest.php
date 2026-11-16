<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AgentPackage;
use App\Models\ListingProcess;
use App\Models\Package;
use App\Models\User;
use Database\Seeders\ListingProcessDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListingProcessLockedStepsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->agent = User::factory()->create(['role' => UserRole::Agent]);
        $package = Package::factory()->create(['max_listing_processes' => 10]);
        AgentPackage::factory()->create(['user_id' => $this->agent->id, 'package_id' => $package->id]);
    }

    private function agentProcess(): ListingProcess
    {
        return ListingProcess::create([
            'name' => 'Agent Residential',
            'type' => 'custom',
            'status' => 'active',
            'agent_id' => $this->agent->id,
            'config' => ListingProcessDefinitions::getResidentialProcessConfig(),
        ]);
    }

    private function save(User $user, ListingProcess $process, array $config): ListingProcess
    {
        $this->actingAs($user)->putJson("/api/listing-processes/{$process->id}", ['config' => $config])->assertOk();

        return $process->refresh();
    }

    private function ids(array $config): array
    {
        return array_column($config, 'id');
    }

    public function test_new_process_gets_the_three_locked_steps(): void
    {
        $id = $this->actingAs($this->agent)->postJson('/api/listing-processes', ['name' => 'Empty'])
            ->assertCreated()->json('id');

        $config = ListingProcess::find($id)->config;
        $this->assertSame(['step_property_location', 'step_images_virtual_tours', 'step_payment'], $this->ids($config));
        $this->assertTrue(collect($config)->every(fn ($s) => $s['locked'] === true));
    }

    public function test_edits_to_a_locked_step_are_ignored(): void
    {
        $process = $this->agentProcess();
        $config = $process->config;
        $map = array_search('step_property_location', $this->ids($config));
        $config[$map]['name'] = 'My Map';
        $config[$map]['sections'][0]['fields'][] = ['id' => 'pin_color', 'name' => 'pin_color', 'label' => 'Pin color', 'type' => 'text'];

        $saved = collect($this->save($this->agent, $process, $config)->config)->firstWhere('id', 'step_property_location');

        $this->assertSame('Location on the Map', $saved['name']);
        $this->assertSame(['latitude', 'longitude'], array_column($saved['sections'][0]['fields'], 'name'));
    }

    public function test_deleted_locked_steps_are_put_back(): void
    {
        $process = $this->agentProcess();
        $config = array_values(array_filter($process->config, fn ($s) => empty($s['locked'])));

        $ids = $this->ids($this->save($this->agent, $process, $config)->config);

        $this->assertContains('step_property_location', $ids);
        $this->assertContains('step_images_virtual_tours', $ids);
        $this->assertSame('step_payment', end($ids));
    }

    public function test_map_and_pictures_can_be_moved_but_payment_stays_last(): void
    {
        $process = $this->agentProcess();
        // New order chosen in the builder: Payment dragged to the top, Map moved to step 5, Pictures to step 6
        $order = [
            'step_payment',
            'step_property_type_address',
            'step_property_information',
            'step_property_features',
            'step_property_location',
            'step_images_virtual_tours',
            'step_room_data',
            'step_disclosures_appliances',
            'step_disclosures_property',
        ];
        $byId = collect($process->config)->keyBy('id');
        $config = array_map(fn ($id) => $byId[$id], $order);

        $saved = $this->save($this->agent, $process, $config)->config;

        $this->assertSame([
            'step_property_type_address',
            'step_property_information',
            'step_property_features',
            'step_property_location',
            'step_images_virtual_tours',
            'step_room_data',
            'step_disclosures_appliances',
            'step_disclosures_property',
            'step_payment',
        ], $this->ids($saved));
        $this->assertSame(range(1, 9), array_column($saved, 'order'));
    }

    public function test_admin_cannot_change_locked_steps_either(): void
    {
        $process = ListingProcess::create([
            'name' => 'System Land', 'type' => 'default', 'status' => 'active', 'agent_id' => null,
            'config' => ListingProcessDefinitions::getVacantLandProcessConfig(),
        ]);
        $config = array_values(array_filter($process->config, fn ($s) => $s['id'] !== 'step_payment'));

        $ids = $this->ids($this->save($this->admin, $process, $config)->config);

        $this->assertSame('step_payment', end($ids));
    }

    public function test_old_public_info_step_is_removed_on_save(): void
    {
        $process = $this->agentProcess();
        $config = $process->config;
        array_unshift($config, ['id' => 'system_public_info', 'order' => 0, 'name' => 'Public Listing Information', 'type' => 'public_listing', 'sections' => []]);

        $this->assertNotContains('system_public_info', $this->ids($this->save($this->agent, $process, $config)->config));
    }

    public function test_migration_normalizes_existing_processes_and_keeps_customizations(): void
    {
        $old = [
            ['id' => 'system_public_info', 'order' => 1, 'name' => 'Public Listing Information', 'type' => 'public_listing', 'isSystem' => true, 'sections' => []],
            ['id' => 'step_property_type_address', 'order' => 2, 'name' => 'Address (Agent Edition)', 'sections' => []],
            ['id' => 'step_property_location', 'order' => 3, 'name' => 'Edited Map', 'sections' => []],
            ['id' => 'step_custom_agent', 'order' => 4, 'name' => 'Agent Extra Step', 'sections' => []],
            ['id' => 'step_images_virtual_tours', 'order' => 5, 'name' => 'Pictures', 'sections' => []],
        ];
        $id = DB::table('listing_processes')->insertGetId([
            'name' => 'Old Shape', 'type' => 'custom', 'status' => 'active', 'agent_id' => $this->agent->id,
            'config' => json_encode($old), 'created_at' => now(), 'updated_at' => now(),
        ]);

        (require database_path('migrations/2026_11_16_000001_normalize_listing_process_steps.php'))->up();

        $config = ListingProcess::find($id)->config;
        $this->assertSame([
            'step_property_type_address',
            'step_property_location',
            'step_custom_agent',
            'step_images_virtual_tours',
            'step_payment',
        ], $this->ids($config));
        $this->assertSame('Address (Agent Edition)', $config[0]['name']);
        $this->assertSame('Location on the Map', $config[1]['name']);
    }
}
