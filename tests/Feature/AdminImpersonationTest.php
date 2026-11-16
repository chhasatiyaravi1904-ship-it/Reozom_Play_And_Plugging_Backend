<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AdminActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $seller;

    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Admin Alice']);
        $this->admin->givePermissionTo(Permission::findOrCreate('manage-users'));

        $this->seller = User::factory()->create(['role' => UserRole::SellerBuyer, 'name' => 'Seller Sam', 'is_active' => true]);
        $this->agent = User::factory()->create(['role' => UserRole::Agent, 'is_active' => true]);
    }

    private function impersonate(User $target): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->postJson("/api/admin/users/{$target->id}/impersonate");
    }

    /** Call the API with a plain bearer token (like the frontend does). */
    private function asBearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_admin_logs_in_as_seller_and_me_shows_the_admin(): void
    {
        $res = $this->impersonate($this->seller)->assertOk();
        $token = $res->json('data.token');

        $this->assertSame($this->seller->id, $res->json('data.user.id'));

        $me = $this->asBearer($token)->getJson('/api/auth/me')->assertOk();
        $this->assertSame($this->seller->id, $me->json('data.id'));
        $this->assertSame(['id' => $this->admin->id, 'name' => 'Admin Alice'], $me->json('data.impersonatedBy'));
    }

    public function test_admin_can_log_in_as_an_agent(): void
    {
        $this->impersonate($this->agent)->assertOk()->assertJsonPath('data.user.id', $this->agent->id);
    }

    public function test_token_expires_after_two_hours(): void
    {
        $this->impersonate($this->seller)->assertOk();

        $token = PersonalAccessToken::where('tokenable_id', $this->seller->id)->firstOrFail();
        $this->assertSame("impersonation:{$this->admin->id}", $token->name);
        $this->assertTrue($token->expires_at->between(now()->addMinutes(119), now()->addMinutes(121)));
    }

    public function test_normal_login_has_no_impersonator(): void
    {
        $token = $this->seller->createToken('api-token')->plainTextToken;

        $this->asBearer($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.impersonatedBy', null);
    }

    public function test_cannot_log_in_as_another_admin(): void
    {
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin]);

        $this->impersonate($otherAdmin)->assertForbidden();
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $otherAdmin->id)->count());
    }

    public function test_cannot_log_in_as_an_inactive_user(): void
    {
        $this->seller->update(['is_active' => false]);

        $this->impersonate($this->seller)->assertStatus(422);
    }

    public function test_non_admins_cannot_impersonate(): void
    {
        $this->actingAs($this->agent)->postJson("/api/admin/users/{$this->seller->id}/impersonate")->assertForbidden();
        $this->actingAs($this->seller)->postJson("/api/admin/users/{$this->agent->id}/impersonate")->assertForbidden();
    }

    public function test_impersonated_session_cannot_start_another_impersonation(): void
    {
        $token = $this->impersonate($this->agent)->json('data.token');

        $this->asBearer($token)->postJson("/api/admin/users/{$this->seller->id}/impersonate")->assertForbidden();
    }

    public function test_stop_deletes_the_temporary_token(): void
    {
        $token = $this->impersonate($this->seller)->json('data.token');

        $this->asBearer($token)->postJson('/api/auth/stop-impersonation')->assertOk();

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $this->seller->id)->count());
        $this->asBearer($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_stop_is_refused_for_a_normal_login(): void
    {
        $token = $this->seller->createToken('api-token')->plainTextToken;

        $this->asBearer($token)->postJson('/api/auth/stop-impersonation')->assertStatus(422);
        $this->assertSame(1, PersonalAccessToken::where('tokenable_id', $this->seller->id)->count());
    }

    public function test_start_and_stop_are_written_to_the_admin_activity_log(): void
    {
        $token = $this->impersonate($this->seller)->json('data.token');
        $this->asBearer($token)->postJson('/api/auth/stop-impersonation')->assertOk();

        $logs = AdminActivityLog::where('user_id', $this->admin->id)->pluck('action')->all();
        $this->assertContains('impersonation_start', $logs);
        $this->assertContains('impersonation_stop', $logs);
    }
}
