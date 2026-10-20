<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Http\Resources\PublicListingResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PublicListingController extends Controller
{
    /**
     * Display a listing of public active/submitted listings.
     */
    public function index(Request $request): JsonResponse
    {
        // Approved Rule: Public listings must be published. Draft, submitted,
        // or unpublished listings are excluded from public discovery.
        $query = Listing::published()
            ->with(['answers', 'images' => function ($query) {
                $query->where('is_public', true);
            }])
            ->latest();
        
        // Basic pagination
        $listings = $query->paginate(15);
        
        return response()->json([
            'success' => true,
            'data' => PublicListingResource::collection($listings->items()),
            'meta' => [
                'current_page' => $listings->currentPage(),
                'last_page' => $listings->lastPage(),
                'per_page' => $listings->perPage(),
                'total' => $listings->total(),
            ]
        ]);
    }

    /**
     * Display a specific public listing.
     */
    public function show(Listing $listing): JsonResponse
    {
        $listing->loadMissing(['answers', 'images' => function ($query) {
            $query->where('is_public', true);
        }]);

        // Only allow published listings to be viewed publicly
        if (! $listing->isPublished()) {
            return response()->json([
                'success' => false,
                'message' => 'Listing not found or not active.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new PublicListingResource($listing)
        ]);
    }
}
