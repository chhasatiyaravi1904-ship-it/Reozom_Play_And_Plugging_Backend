<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FiltersAndPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePackageRequest;
use App\Http\Requests\Admin\UpdatePackageRequest;
use App\Http\Resources\PackageResource;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class PackageController extends Controller
{
    use FiltersAndPaginates;

    private const SORTABLE = ['name', 'price', 'duration_days', 'sort_order', 'created_at'];

    public function permissions(): JsonResponse
    {
        $permissions = \Spatie\Permission\Models\Permission::pluck('name');
        return api_success($permissions);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Package::query();

        $this->applySearch($query, $request);
        $this->applyActiveFilter($query, $request);
        $this->applySort($query, $request, self::SORTABLE, 'sort_order');

        return $this->paginatedResponse($query, $request, PackageResource::class);
    }

    public function store(StorePackageRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $features = $validated['features'] ?? [];
        unset($validated['features']);

        $roleName = 'package-'.$validated['slug'];

        $package = Package::create([
            ...$validated,
            'role_name' => $roleName,
            'sort_order' => $request->integer('sort_order', 0),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $role = Role::findOrCreate($package->role_name, 'web');
        if (!empty($features)) {
            // Only sync valid permissions
            $validPermissions = \Spatie\Permission\Models\Permission::whereIn('name', $features)->pluck('name');
            $role->syncPermissions($validPermissions);
        }

        return api_success(new PackageResource($package), 'Package created.', 201);
    }

    public function show(Package $package): JsonResponse
    {
        return api_success(new PackageResource($package));
    }

    public function update(UpdatePackageRequest $request, Package $package): JsonResponse
    {
        $validated = $request->validated();
        $hasFeaturesKey = array_key_exists('features', $validated);
        $features = $validated['features'] ?? [];
        unset($validated['features']);

        $package->update($validated);

        if ($hasFeaturesKey) {
            $role = Role::findOrCreate($package->role_name, 'web');
            $validPermissions = \Spatie\Permission\Models\Permission::whereIn('name', $features)->pluck('name');
            $role->syncPermissions($validPermissions);
        }

        return api_success(new PackageResource($package), 'Package updated.');
    }

    public function destroy(Package $package): JsonResponse
    {
        if ($package->agentPackages()->exists()) {
            return api_error('Cannot delete a package that agents have already selected.', 422);
        }

        $package->delete();

        return api_success(null, 'Package deleted.');
    }
}
