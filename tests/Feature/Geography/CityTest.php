<?php

namespace Tests\Feature\Geography;

use App\Models\City;
use App\Models\County;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CityTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test-token')->plainTextToken;
    }

    private function userWithManageCities(): User
    {
        $permission = Permission::findOrCreate('manage-cities');
        $role = Role::findOrCreate('cities-manager-test');
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_index_lists_cities(): void
    {
        City::factory()->count(3)->create();
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/cities');

        $response->assertOk();
        $response->assertJsonCount(3, 'data.items');
    }

    public function test_index_can_filter_by_county(): void
    {
        $county = County::factory()->create();
        City::factory()->for($county)->count(2)->create();
        City::factory()->create();
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/cities?county_id={$county->id}");

        $response->assertOk();
        $response->assertJsonCount(2, 'data.items');
    }

    public function test_index_paginates_results(): void
    {
        City::factory()->count(5)->create();
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/cities?per_page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.items');
        $response->assertJsonPath('data.meta.total', 5);
    }

    public function test_show_returns_404_for_missing_city(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/cities/'.Str::uuid());

        $response->assertNotFound();
    }

    public function test_a_user_with_permission_can_create_a_city(): void
    {
        $county = County::factory()->create();
        $user = $this->userWithManageCities();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/cities', [
                'name' => 'Ann Arbor',
                'slug' => 'ann-arbor',
                'code' => '0001',
                'county_id' => $county->id,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.countyId', $county->id);
    }

    public function test_store_rejects_an_invalid_county_id(): void
    {
        $user = $this->userWithManageCities();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/cities', [
                'name' => 'Ann Arbor',
                'slug' => 'ann-arbor',
                'code' => '0001',
                'county_id' => Str::uuid(),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['county_id']);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = $this->userWithManageCities();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/cities', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'slug', 'code', 'county_id']);
    }

    public function test_store_rejects_a_duplicate_code_within_the_same_county(): void
    {
        $county = County::factory()->create();
        City::factory()->for($county)->create(['code' => '0001']);
        $user = $this->userWithManageCities();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/cities', [
                'name' => 'Another City',
                'slug' => 'another-city',
                'code' => '0001',
                'county_id' => $county->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    public function test_a_user_without_permission_cannot_create_a_city(): void
    {
        $county = County::factory()->create();
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/cities', [
                'name' => 'Ann Arbor',
                'slug' => 'ann-arbor',
                'code' => '0001',
                'county_id' => $county->id,
            ]);

        $response->assertForbidden();
    }

    public function test_a_user_with_permission_can_update_a_city(): void
    {
        $city = City::factory()->create(['name' => 'Old Name']);
        $user = $this->userWithManageCities();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->putJson("/api/cities/{$city->id}", ['name' => 'New Name']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
    }

    public function test_a_user_with_permission_can_delete_a_city(): void
    {
        $city = City::factory()->create();
        $user = $this->userWithManageCities();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->deleteJson("/api/cities/{$city->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('cities', ['id' => $city->id]);
    }

    public function test_cities_endpoints_require_authentication(): void
    {
        $this->getJson('/api/cities')->assertUnauthorized();
        $this->postJson('/api/cities', [])->assertUnauthorized();
    }
}
