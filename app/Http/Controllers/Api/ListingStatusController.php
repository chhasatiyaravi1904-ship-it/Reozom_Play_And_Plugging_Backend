<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Services\ListingStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ListingStatusController extends Controller
{
    public function __construct(
        protected ListingStatusService $listingStatusService
    ) {}

    /**
     * Get the status history timeline for a listing.
     */
    public function history(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('view', $listing);

        $history = $listing->statusHistories()
            ->with('actor:id,name,first_name,last_name,email')
            ->get();

        return response()->json([
            'listing_id' => $listing->id,
            'current_status' => $listing->status,
            'history' => $history,
        ]);
    }

    /**
     * Manually transition listing status (e.g., suspend, withdraw, cancel, or admin override).
     */
    public function transition(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:draft,in_progress,submitted,published,under_contract,sold,withdrawn,suspended,canceled,expired'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'force' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $isAdmin = $user->hasRole('admin');
        $force = $isAdmin && !empty($validated['force']);
        $actorRole = $isAdmin ? 'admin' : ($user->id === $listing->user_id ? 'seller' : 'agent');

        $updatedListing = $this->listingStatusService->transition(
            $listing,
            $validated['status'],
            $user,
            $actorRole,
            $validated['comment'] ?? null,
            null,
            $force
        );

        return response()->json([
            'message' => "Listing status successfully changed to {$updatedListing->status}.",
            'listing' => $updatedListing,
        ]);
    }
}
