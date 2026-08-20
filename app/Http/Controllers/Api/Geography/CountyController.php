<?php

namespace App\Http\Controllers\Api\Geography;

use App\Http\Controllers\Concerns\FiltersAndPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Geography\StoreCountyRequest;
use App\Http\Requests\Geography\UpdateCountyRequest;
use App\Http\Resources\CountyResource;
use App\Models\County;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountyController extends Controller
{
    use FiltersAndPaginates;

    private const SORTABLE = ['name', 'code', 'created_at'];

    public function index(Request $request): JsonResponse
    {
        $query = County::query()->with('state');

        $this->applySearch($query, $request);
        $this->applyActiveFilter($query, $request);
        $this->applyExactFilter($query, $request, 'state_id');
        $this->applySort($query, $request, self::SORTABLE);

        return $this->paginatedResponse($query, $request, CountyResource::class);
    }

    public function store(StoreCountyRequest $request): JsonResponse
    {
        $county = County::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return api_success(new CountyResource($county), 'County created.', 201);
    }

    public function show(County $county): JsonResponse
    {
        return api_success(new CountyResource($county->load('state')));
    }

    public function update(UpdateCountyRequest $request, County $county): JsonResponse
    {
        $county->update($request->validated());

        return api_success(new CountyResource($county), 'County updated.');
    }

    public function destroy(County $county): JsonResponse
    {
        $county->delete();

        return api_success(null, 'County deleted.');
    }
}
