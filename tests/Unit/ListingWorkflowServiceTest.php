<?php

namespace Tests\Unit;

use App\Models\ListingProcess;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\ListingWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $service;
    protected $agent1;
    protected $agent2;
    protected $package1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ListingWorkflowService();
        $this->agent1 = User::factory()->create(['role' => 'agent']);
        $this->agent2 = User::factory()->create(['role' => 'agent']);
        $this->package1 = ServicePackage::create([
            'agent_id' => $this->agent1->id,
            'name' => 'Package 1',
            'is_active' => true,
        ]);
    }

    public function test_agent_custom_process_exists()
    {
        $customProcess = ListingProcess::create([
            'name' => 'Custom',
            'type' => 'custom',
            'status' => 'active',
            'agent_id' => $this->agent1->id,
            'service_package_id' => $this->package1->id,
            'config' => [['step' => 1]],
        ]);

        $resolved = $this->service->resolveListingProcess($this->package1);

        $this->assertEquals($customProcess->id, $resolved->id);
    }

    public function test_agent_custom_process_does_not_exist_resolves_to_admin_default()
    {
        $adminProcess = ListingProcess::create([
            'name' => 'Admin Default',
            'type' => 'default',
            'status' => 'active',
            'agent_id' => null,
            'config' => [['step' => 1]],
        ]);

        $resolved = $this->service->resolveListingProcess($this->package1);

        $this->assertEquals($adminProcess->id, $resolved->id);
    }

    public function test_admin_default_inactive_throws_exception()
    {
        ListingProcess::create([
            'name' => 'Admin Default',
            'type' => 'default',
            'status' => 'draft',
            'agent_id' => null,
            'config' => [['step' => 1]],
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The selected service package does not have an active listing process.');

        $this->service->resolveListingProcess($this->package1);
    }

    public function test_package_belonging_to_another_agent()
    {
        // Setup custom process for agent1
        ListingProcess::create([
            'name' => 'Custom',
            'type' => 'custom',
            'status' => 'active',
            'agent_id' => $this->agent1->id,
            'service_package_id' => $this->package1->id,
            'config' => [['step' => 1]],
        ]);

        $package2 = ServicePackage::create([
            'agent_id' => $this->agent2->id,
            'name' => 'Package 2',
            'is_active' => true,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The selected service package does not have an active listing process.');

        $this->service->resolveListingProcess($package2);
    }

    public function test_process_with_empty_config_throws_exception()
    {
        ListingProcess::create([
            'name' => 'Custom',
            'type' => 'custom',
            'status' => 'active',
            'agent_id' => $this->agent1->id,
            'service_package_id' => $this->package1->id,
            'config' => [], // Empty config
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('The selected service package does not have an active listing process.');

        $this->service->resolveListingProcess($this->package1);
    }
}
