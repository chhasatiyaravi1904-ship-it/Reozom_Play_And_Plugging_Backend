<?php

namespace App\Services;

use App\Models\AgentPackage;
use App\Models\Package;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gives an agent a package for its duration (purchase, renewal or switch). Used for free
 * packages directly and for paid packages only after Stripe confirms the payment.
 */
class PackageActivationService
{
    public function __construct(
        private readonly PackageActivityLogger $packageActivityLogger,
    ) {}

    public function activate(User $user, Package $package, ?Request $request = null): AgentPackage
    {
        return DB::transaction(function () use ($user, $package, $request) {
            $previous = $user->agentPackages()->latest('started_at')->first();

            $action = match (true) {
                $previous === null => 'purchased',
                $previous->package_id === $package->id => 'renewed',
                default => 'switched',
            };

            $agentPackage = $user->agentPackages()->create([
                'package_id' => $package->id,
                'started_at' => now(),
                'expires_at' => now()->addDays($package->duration_days),
            ]);

            $this->syncPackageRole($user, $package);

            $this->packageActivityLogger->log($user, $package->id, $previous?->package_id, $action, $request);

            return $agentPackage;
        });
    }

    /**
     * Replace whichever package-tier role the agent currently holds with the new one, leaving
     * any other roles (e.g. admin-portal section roles) untouched.
     */
    private function syncPackageRole(User $user, Package $package): void
    {
        $packageRoleNames = Package::query()->pluck('role_name');

        foreach ($user->roles()->whereIn('name', $packageRoleNames)->get() as $role) {
            $user->removeRole($role);
        }

        // Create the role if missing (guard 'web' to avoid a Sanctum/web guard mismatch)
        $role = \Spatie\Permission\Models\Role::findOrCreate($package->role_name, 'web');
        $user->assignRole($role);
    }
}
