<?php

namespace Tests\Feature\Geography;

use App\Models\County;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CountyTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test-token')->plainTextToken;
    }

    private function userWithManageCounties(): User
    {
        $permission = Permission::findOrCreate('manage-counties');
        $role = Role::findOrCreate('counties-manager-test');
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_index_lists_counties(): void
    {
        County::factory()->count(3)->create();
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/counties');

        $response->assertOk();
        $response->assertJsonCount(3, 'data.items');
    }

    public function test_index_can_filter_by_state(): void
    {
        $state = State::factory()->create();
        County::factory()->for($state)->count(2)->create();
        County::factory()->create();
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/counties?state_id={$state->id}");

        $response->assertOk();
        $response->assertJsonCount(2, 'data.items');
    }

    public function test_index_paginates_results(): void
    {
        County::factory()->count(5)->create();
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/counties?per_page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.items');
        $response->assertJsonPath('data.meta.total', 5);
    }

    public function test_show_returns_404_for_missing_county(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/counties/'.Str::uuid());

        $response->assertNotFound();
    }

    public function test_a_user_with_permission_can_create_a_county(): void
    {
        $state = State::factory()->create();
        $user = $this->userWithManageCounties();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/counties', [
                'name' => 'Los Angeles County',
                'slug' => 'los-angeles-county',
                'code' => '001',
                'state_id' => $state->id,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.stateId', $state->id);
    }

    public function test_store_rejects_an_invalid_state_id(): void
    {
        $user = $this->userWithManageCounties();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/counties', [
                'name' => 'Los Angeles County',
                'slug' => 'los-angeles-county',
                'code' => '001',
                'state_id' => Str::uuid(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['state_id']);
    }

    public function test_store_allows_the_same_code_in_different_states(): void
    {
        $stateA = State::factory()->create();
        $stateB = State::factory()->create();
        County::factory()->for($stateA)->create(['code' => '001']);
        $user = $this->userWithManageCounties();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/counties', [
                'name' => 'Some County',
                'slug' => 'some-county',
                'code' => '001',
                'state_id' => $stateB->id,
            ]);

        $response->assertCreated();
    }

    public function test_store_rejects_a_duplicate_code_within_the_same_state(): void
    {
        $state = State::factory()->create();
        County::factory()->for($state)->create(['code' => '001']);
        $user = $this->userWithManageCounties();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/counties', [
                'name' => 'Another County',
                'slug' => 'another-county',
                'code' => '001',
                'state_id' => $state->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_a_user_without_permission_cannot_create_a_county(): void
    {
        $state = State::factory()->create();
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/counties', [
                'name' => 'Los Angeles County',
                'slug' => 'los-angeles-county',
                'code' => '001',
                'state_id' => $state->id,
            ]);

        $response->assertForbidden();
    }

    public function test_a_user_with_permission_can_update_a_county(): void
    {
        $county = County::factory()->create(['name' => 'Old Name']);
        $user = $this->userWithManageCounties();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson("/api/counties/{$county->id}", ['name' => 'New Name']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
    }

    public function test_a_user_with_permission_can_delete_a_county(): void
    {
        $county = County::factory()->create();
        $user = $this->userWithManageCounties();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson("/api/counties/{$county->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('counties', ['id' => $county->id]);
    }

    public function test_counties_endpoints_require_authentication(): void
    {
        $this->getJson('/api/counties')->assertUnauthorized();
        $this->postJson('/api/counties', [])->assertUnauthorized();
    }
}
