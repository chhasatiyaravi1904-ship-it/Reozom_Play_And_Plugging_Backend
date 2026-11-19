<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeStripeGateway;
use Tests\TestCase;

/**
 * Admin plans and agent plans are created, updated and archived in Stripe as Product + Price.
 * (Tests run the queue synchronously, so the sync happens on save.)
 */
class StripePlanSyncTest extends TestCase
{
    use RefreshDatabase;

    protected FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => 'sk_test_fake', 'services.stripe.currency' => 'usd']);
        $this->stripe = new FakeStripeGateway();
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    private function adminPlan(array $attrs = []): Package
    {
        return Package::create(array_merge([
            'name' => 'Full Access', 'slug' => 'full-'.uniqid(), 'role_name' => 'full-'.uniqid(),
            'description' => 'Everything included', 'price' => 49.99, 'duration_days' => 30, 'is_active' => true,
        ], $attrs));
    }

    private function adminWithManagePackages(): User
    {
        $role = Role::findOrCreate('packages-manager-test');
        $role->givePermissionTo(Permission::findOrCreate('manage-packages'));
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole($role);

        return $admin;
    }

    public function test_creating_a_paid_plan_creates_the_stripe_product_and_price(): void
    {
        $plan = $this->adminPlan()->fresh();

        $this->assertNotNull($plan->stripe_product_id);
        $this->assertNotNull($plan->stripe_price_id);
        $this->assertSame(4999, $plan->stripe_price_amount);
        $this->assertSame('synced', $plan->stripeSyncStatus());

        $product = $this->stripe->products[$plan->stripe_product_id];
        $this->assertSame('Full Access', $product['name']);
        $this->assertSame(['plan_type' => 'agent_package', 'plan_id' => (string) $plan->id], $product['metadata']);
        $this->assertSame($plan->stripe_price_id, $product['default_price']);

        $price = $this->stripe->prices[$plan->stripe_price_id];
        $this->assertSame(['product' => $plan->stripe_product_id, 'unit_amount' => 4999, 'currency' => 'usd'], array_intersect_key($price, array_flip(['product', 'unit_amount', 'currency'])));
    }

    public function test_changing_the_price_creates_a_new_stripe_price_and_archives_the_old_one(): void
    {
        $plan = $this->adminPlan()->fresh();
        $oldPrice = $plan->stripe_price_id;

        $plan->update(['price' => 59.00]);
        $plan->refresh();

        $this->assertNotSame($oldPrice, $plan->stripe_price_id);
        $this->assertSame(5900, $this->stripe->prices[$plan->stripe_price_id]['unit_amount']);
        $this->assertFalse($this->stripe->prices[$oldPrice]['active']);
        $this->assertSame($plan->stripe_price_id, $this->stripe->products[$plan->stripe_product_id]['default_price']);
        $this->assertSame(1, $this->stripe->productCreates, 'The same Stripe Product is kept.');
    }

    public function test_renaming_updates_the_product_without_a_new_price(): void
    {
        $plan = $this->adminPlan()->fresh();
        $priceCount = count($this->stripe->prices);

        $plan->update(['name' => 'Full Access Pro']);

        $this->assertSame('Full Access Pro', $this->stripe->products[$plan->stripe_product_id]['name']);
        $this->assertCount($priceCount, $this->stripe->prices);
    }

    public function test_deactivating_or_making_free_archives_the_product_and_reactivating_restores_it(): void
    {
        $plan = $this->adminPlan()->fresh();

        $plan->update(['is_active' => false]);
        $this->assertFalse($this->stripe->products[$plan->stripe_product_id]['active']);
        $this->assertSame('not_required', $plan->fresh()->stripeSyncStatus());

        $plan->update(['is_active' => true]);
        $this->assertTrue($this->stripe->products[$plan->stripe_product_id]['active']);

        $plan->update(['price' => 0]);
        $this->assertFalse($this->stripe->products[$plan->stripe_product_id]['active']);
    }

    public function test_free_plans_are_not_sent_to_stripe(): void
    {
        $plan = $this->adminPlan(['price' => null])->fresh();

        $this->assertNull($plan->stripe_product_id);
        $this->assertSame('not_required', $plan->stripeSyncStatus());
        $this->assertSame(0, $this->stripe->productCreates);
    }

    public function test_deleting_a_plan_archives_its_product_and_price(): void
    {
        $plan = $this->adminPlan()->fresh();
        [$productId, $priceId] = [$plan->stripe_product_id, $plan->stripe_price_id];

        $plan->delete();

        $this->assertFalse($this->stripe->products[$productId]['active']);
        $this->assertFalse($this->stripe->prices[$priceId]['active']);
    }

    public function test_stripe_outage_keeps_the_plan_and_retry_fixes_it(): void
    {
        $this->stripe->failCatalogWith = 'Stripe is down';
        $plan = $this->adminPlan()->fresh();

        $this->assertDatabaseHas('packages', ['id' => $plan->id]);
        $this->assertSame('failed', $plan->stripeSyncStatus());
        $this->assertSame('Stripe is down', $plan->stripe_sync_error);

        $admin = $this->adminWithManagePackages();
        $this->actingAs($admin)->postJson("/api/admin/packages/{$plan->id}/stripe-sync")->assertStatus(502);

        $this->stripe->failCatalogWith = null;
        $this->actingAs($admin)->postJson("/api/admin/packages/{$plan->id}/stripe-sync")
            ->assertOk()
            ->assertJsonPath('data.status', 'synced');

        $this->actingAs($admin)->getJson("/api/admin/packages/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.stripeSync.status', 'synced');
    }

    public function test_agent_plans_sync_with_agent_metadata_and_only_the_owner_can_retry(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $other = User::factory()->create(['role' => 'agent']);

        $id = $this->actingAs($agent)
            ->postJson('/api/agent/service-packages', ['name' => 'Full MLS', 'price' => 295, 'is_active' => true])
            ->assertCreated()
            ->json('id');

        $plan = ServicePackage::findOrFail($id);
        $this->assertSame('synced', $plan->stripeSyncStatus());
        $this->assertSame((string) $agent->id, $this->stripe->products[$plan->stripe_product_id]['metadata']['agent_id']);
        $this->assertSame(29500, $this->stripe->prices[$plan->stripe_price_id]['unit_amount']);

        $this->actingAs($agent)->getJson('/api/agent/service-packages')
            ->assertOk()
            ->assertJsonPath('0.stripe_sync.status', 'synced');

        $this->actingAs($other)->postJson("/api/agent/service-packages/{$id}/stripe-sync")->assertForbidden();
        $this->actingAs($agent)->postJson("/api/agent/service-packages/{$id}/stripe-sync")->assertOk();
    }

    public function test_nothing_is_sent_when_stripe_is_not_configured(): void
    {
        config(['services.stripe.secret' => null]);

        $plan = $this->adminPlan()->fresh();

        $this->assertNull($plan->stripe_product_id);
        $this->assertSame('pending', $plan->stripeSyncStatus());
        $this->assertSame(0, $this->stripe->productCreates);
    }
}
