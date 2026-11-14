<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminListingController extends Controller
{
    /**
     * Display a paginated collection of listings for admin moderation.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('moderate', Listing::class);

        $query = Listing::with([
            'user:id,name,email,first_name,last_name,phone,role',
            'servicePackage:id,name,price',
            'moderator:id,name,email',
        ]);

        // Search by reference code, address, city, or seller details
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('reference_code', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhere('zip', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // Status filter
        if ($status = $request->input('status')) {
            if ($status !== 'all') {
                $query->where('status', $status);
            }
        }

        // Moderation status filter
        if ($modStatus = $request->input('moderation_status')) {
            $query->where('moderation_status', $modStatus);
        }

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $listings = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $listings->items(),
            'meta' => [
                'current_page' => $listings->currentPage(),
                'last_page' => $listings->lastPage(),
                'per_page' => $listings->perPage(),
                'total' => $listings->total(),
            ]
        ]);
    }

    /**
     * Display full details of a listing for administrative review.
     */
    public function show(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('moderate', Listing::class);

        $listing->load([
            'user',
            'servicePackage.agent',
            'listingProcess',
            'answers',
            'images',
            'documents',
            'moderator',
        ]);

        return api_success($listing);
    }

    /**
     * Approve and publish a listing.
     */
    public function publish(Request $request, Listing $listing, \App\Services\ListingStatusService $statusService): JsonResponse
    {
        Gate::authorize('moderate', Listing::class);

        $statusService->transition(
            $listing,
            'published',
            $request->user(),
            'admin',
            'Approved and published by admin',
            null,
            true
        );

        $listing->update([
            'moderation_status' => 'approved',
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
            'rejection_reason' => null,
        ]);

        return api_success($listing->load('moderator'), 'Listing approved and published successfully.');
    }

    /**
     * Unpublish an active listing. Immediately removes it from public discovery.
     */
    public function unpublish(Request $request, Listing $listing, \App\Services\ListingStatusService $statusService): JsonResponse
    {
        Gate::authorize('moderate', Listing::class);

        $statusService->transition(
            $listing,
            'unpublished',
            $request->user(),
            'admin',
            'Unpublished by admin',
            null,
            true
        );

        $listing->update([
            'moderation_status' => 'unpublished_by_admin',
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
        ]);

        return api_success($listing->load('moderator'), 'Listing unpublished successfully.');
    }

    /**
     * Reject a listing with required feedback/rejection reason.
     */
    public function reject(Request $request, Listing $listing, \App\Services\ListingStatusService $statusService): JsonResponse
    {
        Gate::authorize('moderate', Listing::class);

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $statusService->transition(
            $listing,
            'rejected',
            $request->user(),
            'admin',
            $validated['rejection_reason'],
            null,
            true
        );

        $listing->update([
            'moderation_status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason'],
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
        ]);

        return api_success($listing->load('moderator'), 'Listing rejected with feedback.');
    }

    /**
     * Override or update listing status directly with required audit comment.
     * Replicates Reozom Administration ListingsController@changeStatus
     */
    public function changeStatus(Request $request, Listing $listing, \App\Services\ListingStatusService $statusService): JsonResponse
    {
        Gate::authorize('moderate', Listing::class);

        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                'in:-1,0,1,2,3,4,5,6,7,draft,in_progress,submitted,published,active,under_contract,pending,ccs,contingent_continue_to_show,sold,withdrawn,suspended,canceled,expired,unpublished,rejected'
            ],
            'comment' => ['required', 'string', 'max:250'],
            'anticipated_closed_date' => ['nullable', 'date'],
            'agent' => ['nullable', 'in:1,2'],
            'agent_name' => ['nullable', 'string', 'max:150'],
            'closed_date' => ['nullable', 'date'],
            'sales_price' => ['nullable', 'numeric', 'min:0'],
            'sellers_concessions' => ['nullable', 'in:1,2'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'finance_type' => ['nullable', 'string', 'max:64'],
        ]);

        $metadata = array_filter([
            'anticipated_closed_date' => $validated['anticipated_closed_date'] ?? null,
            'agent' => $validated['agent'] ?? null,
            'agent_name' => $validated['agent_name'] ?? null,
            'closed_date' => $validated['closed_date'] ?? null,
            'sales_price' => $validated['sales_price'] ?? null,
            'sellers_concessions' => $validated['sellers_concessions'] ?? null,
            'amount' => $validated['amount'] ?? null,
            'finance_type' => $validated['finance_type'] ?? null,
        ], fn ($val) => !is_null($val));

        $updatedListing = $statusService->transition(
            $listing,
            $validated['status'],
            $request->user(),
            'admin',
            $validated['comment'],
            !empty($metadata) ? $metadata : null,
            true
        );

        return api_success(
            $updatedListing->load(['moderator', 'soldDetails']),
            "Listing status successfully updated to {$updatedListing->status}."
        );
    }
}
