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
        $roleName = 'package-'.$request->validated('slug');

        $package = Package::create([
            ...$request->validated(),
            'role_name' => $roleName,
            'sort_order' => $request->integer('sort_order', 0),
            'is_active' => $request->boolean('is_active', true),
        ]);

        // Every package needs a matching role for agent selection to sync
        // permissions onto — see Api\PackageController::select().
        Role::findOrCreate($package->role_name);

        return api_success(new PackageResource($package), 'Package created.', 201);
    }

    public function show(Package $package): JsonResponse
    {
        return api_success(new PackageResource($package));
    }

    public function update(UpdatePackageRequest $request, Package $package): JsonResponse
    {
        $package->update($request->validated());

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
