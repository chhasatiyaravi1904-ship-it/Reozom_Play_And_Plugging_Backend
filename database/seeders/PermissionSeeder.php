<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Fine-grained admin-portal permissions, one per sidebar section. These
     * sit alongside (not instead of) the coarse admin/agent/seller/buyer
     * UserRole enum that gates portal access — this layer controls which
     * sections a given admin/agent can act on once inside the portal.
     */
    public function run(): void
    {
        $permissionNames = [
            'manage-users',
            'manage-listings',
            'manage-listing-processes',
            'manage-disclosures',
            'manage-mls',
            'manage-states',
            'manage-counties',
            'manage-cities',
            'manage-packages',
        ];

        $permissions = collect($permissionNames)->mapWithKeys(
            fn (string $name) => [$name => Permission::findOrCreate($name)]
        );

        // Pass model instances rather than names: syncPermissions() resolves
        // string names through the package's cached permissions collection,
        // which isn't guaranteed to see rows created moments earlier in the
        // same process/seeder run.
        $superAdmin = Role::findOrCreate('super-admin');
        $superAdmin->syncPermissions($permissions->values());

        $contentManager = Role::findOrCreate('content-manager');
        $contentManager->syncPermissions($permissions->only([
            'manage-listings',
            'manage-listing-processes',
            'manage-disclosures',
        ])->values());

        User::where('email', 'admin@example.com')->first()?->syncRoles([$superAdmin]);
        User::where('email', 'agent@example.com')->first()?->syncRoles([$contentManager]);
    }
}
