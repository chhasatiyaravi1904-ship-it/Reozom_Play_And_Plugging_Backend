<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PackageResource;
use App\Http\Resources\UserResource;
use App\Models\Package;
use App\Models\User;
use App\Services\PackageActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PackageController extends Controller
{
    public function __construct(
        private readonly PackageActivityLogger $packageActivityLogger,
    ) {}

    /**
     * List the packages an agent can choose from.
     */
    public function index(): JsonResponse
    {
        $packages = Package::active()->orderBy('sort_order')->get();

        return api_success(PackageResource::collection($packages));
    }

    /**
     * Select (or renew/switch to) a package. No payment gateway yet — this
     * simply records the selection, extends access by the package's
     * duration, and syncs the matching permission role.
     */
    public function select(Request $request, Package $package): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAgent()) {
            return api_error('Only agents can select a package.', 403);
        }

        if (! $package->is_active) {
            return api_error('This package is not available.', 422);
        }

        $previous = $user->agentPackages()->latest('started_at')->first();

        $action = match (true) {
            $previous === null => 'purchased',
            $previous->package_id === $package->id => 'renewed',
            default => 'switched',
        };

        DB::transaction(function () use ($user, $package, $previous, $action, $request) {
            $user->agentPackages()->create([
                'package_id' => $package->id,
                'started_at' => now(),
                'expires_at' => now()->addDays($package->duration_days),
            ]);

            $this->syncPackageRole($user, $package);

            $this->packageActivityLogger->log(
                $user,
                $package->id,
                $previous?->package_id,
                $action,
                $request,
            );
        });

        $user->load('currentAgentPackage.package');

        return api_success(new UserResource($user), 'Package selected.');
    }

    /**
     * Replace whichever package-tier role the agent currently holds with
     * the newly selected one, leaving any other roles (e.g. admin-portal
     * section roles) untouched.
     */
    private function syncPackageRole(User $user, Package $package): void
    {
        $packageRoleNames = Package::query()->pluck('role_name');

        $rolesToRemove = $user->roles()->whereIn('name', $packageRoleNames)->get();
        foreach ($rolesToRemove as $role) {
            $user->removeRole($role);
        }

        // Ensure the role exists before assigning it to avoid RoleDoesNotExist exception
        // Explicitly set the guard to 'web' to prevent Sanctum vs Web guard mismatch errors
        $role = \Spatie\Permission\Models\Role::findOrCreate($package->role_name, 'web');
        $user->assignRole($role);
    }
}
