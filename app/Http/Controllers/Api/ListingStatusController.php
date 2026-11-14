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
     * Get the status history timeline and closing details for a listing.
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
            'status_changed_date' => $listing->status_changed_date,
            'sold_details' => $listing->soldDetails,
            'history' => $history,
        ]);
    }

    /**
     * Manually transition listing status (e.g., suspend, withdraw, cancel, put under contract, or mark sold).
     * Replicates Reozom ListingsUpdateStatusService workflows and validations.
     */
    public function transition(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);

        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                'in:draft,in_progress,submitted,published,active,under_contract,pending,ccs,contingent_continue_to_show,sold,withdrawn,suspended,canceled,expired,-1,0,1,2,3,4,5,6,7'
            ],
            'comment' => ['nullable', 'string', 'max:1000'],
            'force' => ['nullable', 'boolean'],
            // Pending / CCS parameters
            'anticipated_closed_date' => ['nullable', 'date'],
            'agent' => ['nullable', 'in:1,2'],
            'agent_name' => ['nullable', 'string', 'max:150'],
            'company' => ['nullable', 'string', 'max:150'],
            'purchase_agreement' => ['nullable', 'string', 'max:500'],
            // Sold parameters
            'closed_date' => ['nullable', 'date'],
            'sales_price' => ['nullable', 'numeric', 'min:0'],
            'sellers_concessions' => ['nullable', 'in:1,2'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'finance_type' => ['nullable', 'string', 'max:64'],
            'closing_document' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $isAdmin = ($user->role === 'admin' || (method_exists($user, 'hasRole') && $user->roles()->where('name', 'admin')->exists()));
        $force = $isAdmin && !empty($validated['force']);
        $actorRole = $isAdmin ? 'admin' : ($user->id === $listing->user_id ? 'seller' : 'agent');

        $metadata = array_filter([
            'anticipated_closed_date' => $validated['anticipated_closed_date'] ?? null,
            'agent' => $validated['agent'] ?? null,
            'agent_name' => $validated['agent_name'] ?? null,
            'company' => $validated['company'] ?? null,
            'purchase_agreement' => $validated['purchase_agreement'] ?? null,
            'closed_date' => $validated['closed_date'] ?? null,
            'sales_price' => $validated['sales_price'] ?? null,
            'sellers_concessions' => $validated['sellers_concessions'] ?? null,
            'amount' => $validated['amount'] ?? null,
            'finance_type' => $validated['finance_type'] ?? null,
            'closing_document' => $validated['closing_document'] ?? null,
        ], fn ($val) => !is_null($val));

        $updatedListing = $this->listingStatusService->transition(
            $listing,
            $validated['status'],
            $user,
            $actorRole,
            $validated['comment'] ?? null,
            !empty($metadata) ? $metadata : null,
            $force
        );

        return response()->json([
            'message' => "Listing status successfully changed to {$updatedListing->status}.",
            'listing' => $updatedListing->load('soldDetails'),
        ]);
    }
}
