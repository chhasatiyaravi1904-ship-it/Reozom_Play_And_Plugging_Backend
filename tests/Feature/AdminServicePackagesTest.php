<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin "Agent Service Packages": read-only list of agent-created packages with the agent's name.
 */
class AdminServicePackagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $laura;
    private User $marcus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->laura = User::factory()->create(['role' => 'agent', 'name' => 'Laura Bennett', 'email' => 'laura@example.com']);
        $this->marcus = User::factory()->create(['role' => 'agent', 'name' => 'Marcus Reed', 'email' => 'marcus@example.com']);

        $gold = ServicePackage::factory()->create(['agent_id' => $this->laura->id, 'name' => 'Gold Listing', 'is_active' => true]);
        ServicePackage::factory()->create(['agent_id' => $this->marcus->id, 'name' => 'Basic MLS', 'is_active' => false]);

        $seller = User::factory()->create(['role' => 'seller']);
        foreach (['1 Main St', '2 Oak St'] as $address) {
            Listing::create([
                'user_id' => $seller->id, 'service_package_id' => $gold->id, 'status' => 'draft',
                'address' => $address, 'city' => 'Detroit', 'state' => 'MI', 'zip' => '48201',
            ]);
        }
    }

    private function list(string $query = '')
    {
        return $this->actingAs($this->admin)->getJson('/api/admin/service-packages'.$query)->assertOk();
    }

    public function test_admin_sees_every_agent_package_with_the_agent_name(): void
    {
        $res = $this->list();

        $res->assertJsonPath('meta.total', 2);
        $rows = collect($res->json('data'))->keyBy('name');
        $this->assertSame('Laura Bennett', $rows['Gold Listing']['agent']['name']);
        $this->assertSame('laura@example.com', $rows['Gold Listing']['agent']['email']);
        $this->assertSame(2, $rows['Gold Listing']['listings_count']);
        $this->assertSame('Marcus Reed', $rows['Basic MLS']['agent']['name']);
        $this->assertSame(0, $rows['Basic MLS']['listings_count']);
    }

    public function test_search_by_package_or_agent_and_filter_by_status(): void
    {
        $this->list('?search=gold')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Gold Listing');
        $this->list('?search=marcus')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Basic MLS');
        $this->list('?search=laura@example')->assertJsonPath('meta.total', 1);
        $this->list('?search=%')->assertJsonPath('meta.total', 0);

        $this->list('?status=active')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.is_active', true);
        $this->list('?status=inactive')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.is_active', false);
    }

    public function test_only_admins_can_see_the_list(): void
    {
        $this->getJson('/api/admin/service-packages')->assertUnauthorized();
        $this->actingAs($this->laura)->getJson('/api/admin/service-packages')->assertForbidden();
    }
}
