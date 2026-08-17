<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_be_assigned_a_role_and_inherits_its_permissions(): void
    {
        $permission = Permission::create(['name' => 'manage-listings']);
        $role = Role::create(['name' => 'content-manager']);
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertTrue($user->hasRole('content-manager'));
        $this->assertTrue($user->hasPermissionTo('manage-listings'));
    }

    public function test_a_user_without_the_role_does_not_have_its_permissions(): void
    {
        $permission = Permission::create(['name' => 'manage-mls']);
        $role = Role::create(['name' => 'mls-manager']);
        $role->givePermissionTo($permission);

        $user = User::factory()->create();

        $this->assertFalse($user->hasPermissionTo('manage-mls'));
    }

    public function test_permissions_returned_by_the_user_resource_reflect_assigned_roles(): void
    {
        $permission = Permission::create(['name' => 'manage-states']);
        $role = Role::create(['name' => 'states-manager']);
        $role->givePermissionTo($permission);

        $user = User::factory()->admin()->create(['email' => 'admin@example.com']);
        $user->assignRole($role);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.user.permissions', ['manage-states']);
    }
}
