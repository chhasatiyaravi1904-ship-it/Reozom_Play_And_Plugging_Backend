<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class PackageSeeder extends Seeder
{
    /**
     * The 3 default agent packages. Each gets a matching `package-{slug}`
     * spatie role (created below) that Api\PackageController::select()
     * syncs onto an agent when they choose it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function packages(): array
    {
        return [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'Basic access for agents just getting started.',
                'duration_days' => 30,
                'sort_order' => 1,
            ],
            [
                'name' => 'Minor Access',
                'slug' => 'minor-access',
                'description' => 'Expanded access for growing agents.',
                'duration_days' => 30,
                'sort_order' => 2,
            ],
            [
                'name' => 'Full Access',
                'slug' => 'full-access',
                'description' => 'Full access to all agent features.',
                'duration_days' => 30,
                'sort_order' => 3,
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::packages() as $data) {
            $roleName = 'package-'.$data['slug'];

            $package = Package::updateOrCreate(
                ['slug' => $data['slug']],
                [...$data, 'role_name' => $roleName, 'is_active' => true],
            );

            Role::findOrCreate($package->role_name);
        }
    }
}
