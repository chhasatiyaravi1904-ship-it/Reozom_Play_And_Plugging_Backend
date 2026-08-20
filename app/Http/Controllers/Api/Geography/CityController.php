<?php

namespace App\Http\Controllers\Api\Geography;

use App\Http\Controllers\Concerns\FiltersAndPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Geography\StoreCityRequest;
use App\Http\Requests\Geography\UpdateCityRequest;
use App\Http\Resources\CityResource;
use App\Models\City;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CityController extends Controller
{
    use FiltersAndPaginates;

    private const SORTABLE = ['name', 'code', 'created_at'];

    public function index(Request $request): JsonResponse
    {
        $query = City::query()->with('county.state');

        $this->applySearch($query, $request);
        $this->applyActiveFilter($query, $request);
        $this->applyExactFilter($query, $request, 'county_id');
        $this->applySort($query, $request, self::SORTABLE);

        return $this->paginatedResponse($query, $request, CityResource::class);
    }

    public function store(StoreCityRequest $request): JsonResponse
    {
        $city = City::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return api_success(new CityResource($city), 'City created.', 201);
    }

    public function show(City $city): JsonResponse
    {
        return api_success(new CityResource($city->load('county.state')));
    }

    public function update(UpdateCityRequest $request, City $city): JsonResponse
    {
        $city->update($request->validated());

        return api_success(new CityResource($city), 'City updated.');
    }

    public function destroy(City $city): JsonResponse
    {
        $city->delete();

        return api_success(null, 'City deleted.');
    }
}
