<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Package;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AgentUserSeeder extends Seeder
{
    /**
     * Seed 5 demo agents, each with an active free package so they can use the agent portal.
     * Must run after PackageSeeder.
     */
    public function run(): void
    {
        $agents = [
            ['Laura', 'Bennett', '(248) 555-0201', 'Bennett Realty Group', '248-555-0301', 'Troy', '48084'],
            ['Marcus', 'Reed', '(313) 555-0202', 'Motor City Homes', '313-555-0302', 'Detroit', '48226'],
            ['Priya', 'Shah', '(734) 555-0203', 'Ann Arbor Home Partners', '734-555-0303', 'Ann Arbor', '48104'],
            ['Kevin', "O'Brien", '(616) 555-0204', 'Lakeshore Realty', '616-555-0304', 'Grand Rapids', '49503'],
            ['Natalie', 'Brooks', '(517) 555-0205', 'Capital Area Realtors', '517-555-0305', 'Lansing', '48933'],
        ];

        // Free = no price set (see PaymentService::isFree); Full Access gives every agent feature.
        $package = Package::where('name', 'Full Access')->first()
            ?? Package::all()->first(fn (Package $p) => PaymentService::isFree($p->price));

        foreach ($agents as [$first, $last, $phone, $company, $office, $city, $zip]) {
            $user = User::updateOrCreate(
                ['email' => strtolower($first.'.'.str_replace("'", '', $last)).'@example.com'],
                [
                    'name' => "{$first} {$last}",
                    'first_name' => $first,
                    'last_name' => $last,
                    'phone' => $phone,
                    'company' => $company,
                    'office_number' => $office,
                    'city' => $city,
                    'state' => 'MI',
                    'zip' => $zip,
                    'password' => Hash::make('password'),
                    'role' => UserRole::Agent,
                    'email_verified_at' => now(),
                    'is_active' => true,
                    'profile_finished' => true,
                ]
            );

            if (! $package || ! PaymentService::isFree($package->price)) {
                continue;
            }

            // Same records AuthController::assignPackage creates for a free plan at signup.
            if (! $user->agentPackages()->where('package_id', $package->id)->where('expires_at', '>', now())->exists()) {
                $user->agentPackages()->create([
                    'package_id' => $package->id,
                    'started_at' => now(),
                    'expires_at' => now()->addDays($package->duration_days),
                ]);
            }
            $user->assignRole(Role::findOrCreate($package->role_name, 'web'));
        }
    }
}
