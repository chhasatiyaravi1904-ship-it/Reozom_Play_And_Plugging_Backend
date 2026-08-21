<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\State;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Minimal, unauthenticated geography lookups for the public registration
 * form — deliberately separate from Api\Geography\StateController /
 * CityController, which are admin-oriented (full CRUD, unfiltered
 * active/inactive records, arbitrary sort/filter params) and sit behind
 * auth:sanctum.
 */
class PublicGeographyController extends Controller
{
    public function states(): JsonResponse
    {
        $states = State::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return api_success($states);
    }

    public function cities(Request $request): JsonResponse
    {
        $stateId = $request->validate([
            'state_id' => ['required', 'uuid', 'exists:states,id'],
        ])['state_id'];

        $cities = City::query()
            ->active()
            ->whereHas('county', fn ($query) => $query->where('state_id', $stateId))
            ->orderBy('name')
            ->get(['id', 'name']);

        return api_success($cities);
    }
}
