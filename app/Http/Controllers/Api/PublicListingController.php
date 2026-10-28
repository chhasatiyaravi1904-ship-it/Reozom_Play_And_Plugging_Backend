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

        // Search text filter
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('address', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhere('state', 'like', "%{$search}%")
                  ->orWhere('zip', 'like', "%{$search}%")
                  ->orWhere('reference_code', 'like', "%{$search}%")
                  ->orWhereHas('answers', function ($ansQ) use ($search) {
                      $ansQ->where('values', 'like', "%{$search}%");
                  });
            });
        }

        // Location specific filter
        if ($location = $request->input('location')) {
            $query->where(function ($q) use ($location) {
                $q->where('city', 'like', "%{$location}%")
                  ->orWhere('state', 'like', "%{$location}%")
                  ->orWhere('zip', 'like', "%{$location}%")
                  ->orWhere('address', 'like', "%{$location}%");
            });
        }

        if ($city = $request->input('city')) {
            $query->where('city', 'like', "%{$city}%");
        }

        if ($state = $request->input('state')) {
            $query->where('state', strtoupper($state));
        }

        if ($zip = $request->input('zip')) {
            $query->where('zip', 'like', "{$zip}%");
        }

        // Property type filter
        if ($propertyType = $request->input('property_type')) {
            if ($propertyType !== 'all') {
                $query->whereHas('answers', function ($ansQ) use ($propertyType) {
                    $ansQ->where('values', 'like', '%"property_type":"' . $propertyType . '"%')
                         ->orWhere('values', 'like', '%"property_type": "' . $propertyType . '"%');
                });
            }
        }

        $perPage = max(1, min((int) $request->input('per_page', 12), 50));
        $listings = $query->paginate($perPage);

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
