<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use App\Services\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * "Listing Agent Packages" on the website: each plan's Included (✓) and Not included (✕)
 * lists are entered by the admin and served by the public packages endpoint.
 */
class PackageFeatureListsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => 'sk_test_fake', 'services.stripe.currency' => 'usd']);
        $this->app->instance(StripeGateway::class, new FakeStripeGateway());

        $role = Role::findOrCreate('packages-manager-test');
        $role->givePermissionTo(Permission::findOrCreate('manage-packages'));
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->assignRole($role);
    }

    private function create(array $overrides = [])
    {
        return $this->actingAs($this->admin)->postJson('/api/admin/packages', array_merge([
            'name' => 'Growth Broker',
            'slug' => 'growth-broker',
            'price' => 149,
            'duration_days' => 30,
            'is_active' => true,
        ], $overrides));
    }

    public function test_admin_saves_included_and_not_included_lists_in_order(): void
    {
        $res = $this->create([
            'included_features' => ['  25 Active Listings ', 'Unlimited ZIP codes', ''],
            'excluded_features' => ['Dynamic Builder Webhooks'],
        ])->assertCreated();

        // Trimmed, blank rows dropped, order kept
        $res->assertJsonPath('data.includedFeatures', ['25 Active Listings', 'Unlimited ZIP codes'])
            ->assertJsonPath('data.excludedFeatures', ['Dynamic Builder Webhooks']);

        $package = Package::where('slug', 'growth-broker')->firstOrFail();
        $this->assertSame(['25 Active Listings', 'Unlimited ZIP codes'], $package->included_features);
    }

    public function test_admin_updates_and_clears_the_lists(): void
    {
        $id = $this->create(['included_features' => ['A', 'B']])->json('data.id');

        $this->actingAs($this->admin)->putJson("/api/admin/packages/{$id}", [
            'included_features' => ['B', 'A', 'C'],
            'excluded_features' => ['D'],
        ])->assertOk()
            ->assertJsonPath('data.includedFeatures', ['B', 'A', 'C'])
            ->assertJsonPath('data.excludedFeatures', ['D']);

        $this->actingAs($this->admin)->putJson("/api/admin/packages/{$id}", ['included_features' => []])
            ->assertOk()->assertJsonPath('data.includedFeatures', []);
    }

    public function test_lists_are_validated(): void
    {
        $this->create(['included_features' => array_map(fn ($i) => "Item {$i}", range(1, 21))])
            ->assertStatus(422)->assertJsonValidationErrors('included_features');
        $this->create(['excluded_features' => [str_repeat('x', 151)]])
            ->assertStatus(422)->assertJsonValidationErrors('excluded_features.0');
        $this->create(['included_features' => ['Same', 'Same']])
            ->assertStatus(422)->assertJsonValidationErrors('included_features.0');
        $this->create(['included_features' => 'not a list'])
            ->assertStatus(422)->assertJsonValidationErrors('included_features');
    }

    public function test_public_endpoint_lists_active_plans_with_their_lists_in_sort_order(): void
    {
        Package::create(['name' => 'Pro', 'slug' => 'pro', 'role_name' => 'package-pro', 'price' => 99, 'duration_days' => 30,
            'sort_order' => 2, 'is_active' => true, 'included_features' => ['10 Listings'], 'excluded_features' => ['API access']]);
        Package::create(['name' => 'Basic', 'slug' => 'basic', 'role_name' => 'package-basic', 'price' => null, 'duration_days' => 30,
            'sort_order' => 1, 'is_active' => true]);
        Package::create(['name' => 'Hidden', 'slug' => 'hidden', 'role_name' => 'package-hidden', 'price' => 10, 'duration_days' => 30,
            'sort_order' => 0, 'is_active' => false, 'included_features' => ['Secret']]);

        $res = $this->getJson('/api/packages')->assertOk();

        $this->assertSame(['Basic', 'Pro'], collect($res->json('data'))->pluck('name')->all());
        $res->assertJsonPath('data.0.includedFeatures', [])
            ->assertJsonPath('data.0.excludedFeatures', [])
            ->assertJsonPath('data.1.includedFeatures', ['10 Listings'])
            ->assertJsonPath('data.1.excludedFeatures', ['API access']);
    }
}
