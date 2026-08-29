<?php

namespace App\Http\Controllers\Api\Mls;

use App\Http\Controllers\Concerns\FiltersAndPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mls\StoreMlsDirectoryRequest;
use App\Http\Requests\Mls\UpdateMlsDirectoryRequest;
use App\Http\Resources\MlsDirectoryResource;
use App\Models\MlsDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MlsDirectoryController extends Controller
{
    use FiltersAndPaginates;

    private const SORTABLE = ['title', 'created_at'];

    public function index(Request $request): JsonResponse
    {
        $query = MlsDirectory::query()->withCount('infos');

        $this->applySearch($query, $request, 'title');
        $this->applySort($query, $request, self::SORTABLE, 'title');

        return $this->paginatedResponse($query, $request, MlsDirectoryResource::class);
    }

    public function store(StoreMlsDirectoryRequest $request): JsonResponse
    {
        $directory = MlsDirectory::create($request->validated());

        return api_success(new MlsDirectoryResource($directory), 'MLS directory created.', 201);
    }

    public function show(MlsDirectory $mlsDirectory): JsonResponse
    {
        return api_success(new MlsDirectoryResource($mlsDirectory->load('infos')));
    }

    public function update(UpdateMlsDirectoryRequest $request, MlsDirectory $mlsDirectory): JsonResponse
    {
        $mlsDirectory->update($request->validated());

        return api_success(new MlsDirectoryResource($mlsDirectory), 'MLS directory updated.');
    }

    public function destroy(MlsDirectory $mlsDirectory): JsonResponse
    {
        $mlsDirectory->delete();

        return api_success(null, 'MLS directory deleted.');
    }
}
