<?php

namespace App\Http\Controllers\Api\Geography;

use App\Http\Controllers\Concerns\FiltersAndPaginates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Geography\StoreStateRequest;
use App\Http\Requests\Geography\UpdateStateRequest;
use App\Http\Resources\StateResource;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StateController extends Controller
{
    use FiltersAndPaginates;

    private const SORTABLE = ['name', 'code', 'created_at'];

    public function index(Request $request): JsonResponse
    {
        $query = State::query();

        $this->applySearch($query, $request);
        $this->applyActiveFilter($query, $request);
        $this->applySort($query, $request, self::SORTABLE);

        return $this->paginatedResponse($query, $request, StateResource::class);
    }

    public function store(StoreStateRequest $request): JsonResponse
    {
        $state = State::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return api_success(new StateResource($state), 'State created.', 201);
    }

    public function show(State $state): JsonResponse
    {
        return api_success(new StateResource($state));
    }

    public function update(UpdateStateRequest $request, State $state): JsonResponse
    {
        $state->update($request->validated());

        return api_success(new StateResource($state), 'State updated.');
    }

    public function destroy(State $state): JsonResponse
    {
        $state->delete();

        return api_success(null, 'State deleted.');
    }
}
