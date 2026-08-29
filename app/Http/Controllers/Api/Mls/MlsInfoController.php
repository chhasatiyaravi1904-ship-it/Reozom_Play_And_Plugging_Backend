<?php

namespace App\Http\Controllers\Api\Mls;

use App\Http\Controllers\Concerns\FiltersAndPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mls\StoreMlsInfoRequest;
use App\Http\Requests\Mls\UpdateMlsInfoRequest;
use App\Http\Resources\MlsInfoResource;
use App\Models\MlsInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MlsInfoController extends Controller
{
    use FiltersAndPaginates;

    private const SORTABLE = ['title', 'created_at'];

    public function index(Request $request): JsonResponse
    {
        $query = MlsInfo::query();

        $this->applySearch($query, $request, 'title');
        $this->applyExactFilter($query, $request, 'mls_directory_id');
        $this->applySort($query, $request, self::SORTABLE, 'created_at');

        return $this->paginatedResponse($query, $request, MlsInfoResource::class);
    }

    public function store(StoreMlsInfoRequest $request): JsonResponse
    {
        $info = MlsInfo::create($request->validated());

        return api_success(new MlsInfoResource($info), 'MLS info created.', 201);
    }

    public function show(MlsInfo $mlsInfo): JsonResponse
    {
        return api_success(new MlsInfoResource($mlsInfo->load('directory')));
    }

    public function update(UpdateMlsInfoRequest $request, MlsInfo $mlsInfo): JsonResponse
    {
        $mlsInfo->update($request->validated());

        return api_success(new MlsInfoResource($mlsInfo), 'MLS info updated.');
    }

    public function destroy(MlsInfo $mlsInfo): JsonResponse
    {
        $mlsInfo->delete();

        return api_success(null, 'MLS info deleted.');
    }
}
