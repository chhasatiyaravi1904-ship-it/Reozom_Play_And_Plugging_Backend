<?php

namespace Tests\Feature;

use App\Models\AgentPackage;
use App\Models\Package;
use App\Models\User;
use App\Services\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

class AdminPackageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => 'sk_test_fake', 'services.stripe.currency' => 'usd']);
        $this->app->instance(StripeGateway::class, new FakeStripeGateway());
    }

    private function packageManager(): User
    {
        $role = Role::findOrCreate('packages-manager-test');
        $role->givePermissionTo(Permission::findOrCreate('manage-packages'));
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole($role);

        return $admin;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Pro Agent',
            'slug' => 'pro-agent',
            'description' => 'For busy agents',
            'price' => 49.99,
            'duration_days' => 30,
            'is_active' => true,
        ], $overrides);
    }

    public function test_admin_can_create_a_package(): void
    {
        $this->actingAs($this->packageManager())
            ->postJson('/api/admin/packages', $this->validPayload())
            ->assertCreated();

        $this->assertDatabaseHas('packages', ['slug' => 'pro-agent', 'duration_days' => 30, 'is_active' => true]);
    }

    public function test_create_validates_input(): void
    {
        $admin = $this->packageManager();
        Package::factory()->create(['slug' => 'taken']);

        $this->actingAs($admin)->postJson('/api/admin/packages', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'slug', 'duration_days']);

        $this->actingAs($admin)->postJson('/api/admin/packages', $this->validPayload([
            'slug' => 'Not A Slug', 'price' => -5, 'duration_days' => 0,
        ]))->assertStatus(422)->assertJsonValidationErrors(['slug', 'price', 'duration_days']);

        $this->actingAs($admin)->postJson('/api/admin/packages', $this->validPayload(['slug' => 'taken']))
            ->assertStatus(422)->assertJsonValidationErrors(['slug']);
    }

    public function test_admin_can_update_a_package(): void
    {
        $package = Package::factory()->create(['name' => 'Old', 'price' => 10]);

        $this->actingAs($this->packageManager())
            ->putJson("/api/admin/packages/{$package->id}", ['name' => 'New Name', 'price' => 25, 'is_active' => false])
            ->assertOk();

        $package->refresh();
        $this->assertSame('New Name', $package->name);
        $this->assertEquals(25, (float) $package->price);
        $this->assertFalse((bool) $package->is_active);
    }

    public function test_admin_can_delete_an_unused_package(): void
    {
        $package = Package::factory()->create();

        $this->actingAs($this->packageManager())
            ->deleteJson("/api/admin/packages/{$package->id}")
            ->assertOk();

        $this->assertDatabaseMissing('packages', ['id' => $package->id]);
    }

    public function test_cannot_delete_a_package_agents_have_selected(): void
    {
        $package = Package::factory()->create();
        $agent = User::factory()->create(['role' => 'agent']);
        AgentPackage::factory()->create(['user_id' => $agent->id, 'package_id' => $package->id]);

        $this->actingAs($this->packageManager())
            ->deleteJson("/api/admin/packages/{$package->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('packages', ['id' => $package->id]);
    }

    public function test_admin_without_permission_and_agents_cannot_change_packages(): void
    {
        $package = Package::factory()->create();
        $plainAdmin = User::factory()->create(['role' => 'admin']);
        $agent = User::factory()->create(['role' => 'agent']);

        $this->actingAs($plainAdmin)->postJson('/api/admin/packages', $this->validPayload())->assertForbidden();
        $this->actingAs($plainAdmin)->deleteJson("/api/admin/packages/{$package->id}")->assertForbidden();
        $this->actingAs($agent)->postJson('/api/admin/packages', $this->validPayload())->assertForbidden();
        $this->actingAs($agent)->getJson('/api/admin/packages')->assertForbidden();
    }
}
