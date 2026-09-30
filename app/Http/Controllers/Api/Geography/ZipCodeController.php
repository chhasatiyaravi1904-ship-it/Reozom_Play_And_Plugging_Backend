<?php

namespace App\Http\Controllers\Api\Geography;

use App\Http\Controllers\Concerns\FiltersAndPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Geography\StoreZipCodeRequest;
use App\Http\Requests\Geography\UpdateZipCodeRequest;
use App\Http\Resources\ZipCodeResource;
use App\Models\ZipCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZipCodeController extends Controller
{
    use FiltersAndPaginates;

    private const SORTABLE = ['id', 'code', 'created_at'];

    public function index(Request $request): JsonResponse
    {
        $query = ZipCode::query()->with(['state', 'county', 'city']);

        $this->applySearch($query, $request, 'code');
        $this->applyActiveFilter($query, $request);
        $this->applyExactFilter($query, $request, 'state_id');
        $this->applyExactFilter($query, $request, 'county_id');
        $this->applyExactFilter($query, $request, 'city_id');
        $this->applySort($query, $request, self::SORTABLE, 'code');

        return $this->paginatedResponse($query, $request, ZipCodeResource::class);
    }

    public function store(StoreZipCodeRequest $request): JsonResponse
    {
        $zipCode = ZipCode::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return api_success(new ZipCodeResource($zipCode->load(['state', 'county', 'city'])), 'ZIP Code created.', 201);
    }

    public function show(ZipCode $zip_code): JsonResponse
    {
        return api_success(new ZipCodeResource($zip_code->load(['state', 'county', 'city'])));
    }

    public function update(UpdateZipCodeRequest $request, ZipCode $zip_code): JsonResponse
    {
        $zip_code->update($request->validated());

        return api_success(new ZipCodeResource($zip_code->load(['state', 'county', 'city'])), 'ZIP Code updated.');
    }

    public function destroy(ZipCode $zip_code): JsonResponse
    {
        // Check if there are constraints that prevent deletion
        // Since we cascade on delete from states/counties/cities, deleting a ZipCode directly is fine.
        $zip_code->delete();

        return api_success(null, 'ZIP Code deleted.');
    }

    public function lookup($code): JsonResponse
    {
        $zipCode = ZipCode::where('code', $code)->with(['state', 'county', 'city'])->first();

        if (!$zipCode) {
            return response()->json(['success' => false, 'message' => 'ZIP Code not found.'], 404);
        }

        return api_success(new ZipCodeResource($zipCode));
    }
}
