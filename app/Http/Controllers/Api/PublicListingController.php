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

        $request->validate([
            'property_type' => ['nullable', 'string', 'max:50'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $propertyType = $request->input('property_type');
        $propertyType = $propertyType === 'all' ? null : $propertyType;
        $minPrice = $request->filled('min_price') ? (float) $request->input('min_price') : null;
        $maxPrice = $request->filled('max_price') ? (float) $request->input('max_price') : null;

        $perPage = max(1, min((int) $request->input('per_page', 12), 50));
        $page = max(1, (int) $request->input('page', 1));

        if ($propertyType === null && $minPrice === null && $maxPrice === null) {
            $listings = $query->paginate($perPage);
        } else {
            // Type and price are resolved from the listing's answers and
            // workflow snapshot (publicPropertyType / publicValue), which SQL
            // can't reproduce, so these filters run on the model.
            $matching = $query->get()->filter(function (Listing $listing) use ($propertyType, $minPrice, $maxPrice) {
                if ($propertyType !== null && $listing->publicPropertyType() !== $propertyType) {
                    return false;
                }
                if ($minPrice !== null || $maxPrice !== null) {
                    $price = self::numericPrice($listing->publicValue('listing_price'));
                    if ($price === null
                        || ($minPrice !== null && $price < $minPrice)
                        || ($maxPrice !== null && $price > $maxPrice)) {
                        return false;
                    }
                }
                return true;
            })->values();

            $listings = new \Illuminate\Pagination\LengthAwarePaginator(
                $matching->forPage($page, $perPage)->values(),
                $matching->count(),
                $perPage,
                $page
            );
        }

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
     * Filter options that actually exist among published listings.
     */
    public function filters(): JsonResponse
    {
        $listings = Listing::published()->with('answers')->get();

        $types = $listings->map(fn (Listing $listing) => $listing->publicPropertyType())
            ->filter()
            ->unique()
            ->sort()
            ->map(fn (string $type) => [
                'value' => $type,
                'label' => Listing::PROPERTY_TYPE_LABELS[$type] ?? ucwords(str_replace('_', ' ', $type)),
            ])
            ->values();

        $prices = $listings->map(fn (Listing $listing) => self::numericPrice($listing->publicValue('listing_price')))
            ->filter(fn ($price) => $price !== null);

        return response()->json([
            'success' => true,
            'data' => [
                'property_types' => $types,
                'price' => [
                    'min' => $prices->min(),
                    'max' => $prices->max(),
                ],
            ],
        ]);
    }

    /** "$450,000" / "450000" / 450000 → 450000.0; anything else → null. */
    private static function numericPrice(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $clean = preg_replace('/[^0-9.]/', '', $value);

        return is_numeric($clean) ? (float) $clean : null;
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
