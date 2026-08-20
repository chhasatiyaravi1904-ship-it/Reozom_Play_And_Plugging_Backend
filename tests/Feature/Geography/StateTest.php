<?php

namespace Tests\Feature\Geography;

use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StateTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test-token')->plainTextToken;
    }

    private function userWithManageStates(): User
    {
        $permission = Permission::findOrCreate('manage-states');
        $role = Role::findOrCreate('states-manager-test');
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_index_lists_states(): void
    {
        State::factory()->count(3)->create();
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/states');

        $response->assertOk();
        $response->assertJsonCount(3, 'data.items');
        $response->assertJsonPath('data.meta.total', 3);
    }

    public function test_index_paginates_results(): void
    {
        State::factory()->count(5)->create();
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/states?per_page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.items');
        $response->assertJsonPath('data.meta.perPage', 2);
        $response->assertJsonPath('data.meta.lastPage', 3);
    }

    public function test_index_can_search_by_name(): void
    {
        State::factory()->create(['name' => 'California']);
        State::factory()->create(['name' => 'Texas']);
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/states?search=cali');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.items');
        $response->assertJsonPath('data.items.0.name', 'California');
    }

    public function test_index_can_filter_by_active_status(): void
    {
        State::factory()->create(['is_active' => true]);
        State::factory()->create(['is_active' => false]);
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/states?is_active=0');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.items');
        $response->assertJsonPath('data.items.0.isActive', false);
    }

    public function test_show_returns_a_state(): void
    {
        $state = State::factory()->create();
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/states/{$state->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $state->id);
    }

    public function test_show_returns_404_for_missing_state(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/states/'.Str::uuid());

        $response->assertNotFound();
    }

    public function test_a_user_with_permission_can_create_a_state(): void
    {
        $user = $this->userWithManageStates();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/states', [
                'name' => 'California',
                'slug' => 'california',
                'code' => 'CA',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'California');
        $response->assertJsonPath('data.isActive', true);
        $this->assertDatabaseHas('states', ['code' => 'CA', 'is_active' => true]);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->userWithManageStates();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/states', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'slug', 'code']);
    }

    public function test_store_rejects_duplicate_slug(): void
    {
        State::factory()->create(['slug' => 'california']);
        $user = $this->userWithManageStates();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/states', [
                'name' => 'Californiaa',
                'slug' => 'california',
                'code' => 'CZ',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['slug']);
    }

    public function test_store_rejects_duplicate_code(): void
    {
        State::factory()->create(['code' => 'CA']);
        $user = $this->userWithManageStates();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/states', [
                'name' => 'California Two',
                'slug' => 'california-two',
                'code' => 'CA',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_a_user_without_permission_cannot_create_a_state(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/states', [
                'name' => 'California',
                'slug' => 'california',
                'code' => 'CA',
            ]);

        $response->assertForbidden();
    }

    public function test_a_user_with_permission_can_update_a_state(): void
    {
        $state = State::factory()->create(['name' => 'Old Name']);
        $user = $this->userWithManageStates();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson("/api/states/{$state->id}", ['name' => 'New Name']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
    }

    public function test_update_allows_keeping_the_same_slug(): void
    {
        $state = State::factory()->create(['slug' => 'california']);
        $user = $this->userWithManageStates();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson("/api/states/{$state->id}", ['slug' => 'california']);

        $response->assertOk();
    }

    public function test_a_user_without_permission_cannot_update_a_state(): void
    {
        $state = State::factory()->create();
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson("/api/states/{$state->id}", ['name' => 'New Name']);

        $response->assertForbidden();
    }

    public function test_a_user_with_permission_can_delete_a_state(): void
    {
        $state = State::factory()->create();
        $user = $this->userWithManageStates();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson("/api/states/{$state->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('states', ['id' => $state->id]);
    }

    public function test_a_user_without_permission_cannot_delete_a_state(): void
    {
        $state = State::factory()->create();
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson("/api/states/{$state->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('states', ['id' => $state->id]);
    }

    public function test_states_endpoints_require_authentication(): void
    {
        $this->getJson('/api/states')->assertUnauthorized();
        $this->postJson('/api/states', [])->assertUnauthorized();
    }
}
