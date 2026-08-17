<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Listings\StoreListingRequest;
use App\Http\Requests\Listings\UpdateListingRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ListingController extends Controller
{
    /**
     * A seller only ever sees their own listings — there is no
     * cross-account browsing here, unlike the admin listings view.
     */
    public function index(Request $request): JsonResponse
    {
        $listings = $request->user()->listings()->latest()->get();

        return api_success(ListingResource::collection($listings));
    }

    public function store(StoreListingRequest $request): JsonResponse
    {
        $listing = $request->user()->listings()->create([
            ...$request->validated(),
            'status' => 'in_progress',
            'steps_completed' => 0,
            'steps_total' => 4,
        ]);

        return api_success(new ListingResource($listing), 'Listing created.', 201);
    }

    public function show(Request $request, Listing $listing): JsonResponse
    {
        $this->authorizeOwner($request, $listing);

        return api_success(new ListingResource($listing));
    }

    public function update(UpdateListingRequest $request, Listing $listing): JsonResponse
    {
        $this->authorizeOwner($request, $listing);

        $listing->update($request->validated());

        return api_success(new ListingResource($listing), 'Listing updated.');
    }

    public function submit(Request $request, Listing $listing): JsonResponse
    {
        $this->authorizeOwner($request, $listing);

        $listing->update([
            'status' => 'submitted',
            'steps_completed' => $listing->steps_total,
        ]);

        return api_success(new ListingResource($listing), 'Listing submitted.');
    }

    private function authorizeOwner(Request $request, Listing $listing): void
    {
        Gate::allowIf(fn () => $listing->user_id === $request->user()->id);
    }
}
