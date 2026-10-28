<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\ListingOffer;
use App\Models\ListingOfferDocument;
use App\Services\OfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OfferController extends Controller
{
    public function __construct(
        protected OfferService $offerService
    ) {}

    /**
     * Submit an offer on a listing (Buyer).
     */
    public function store(Request $request, Listing $listing): JsonResponse
    {
        $validated = $request->validate([
            'offer_amount' => ['required', 'numeric', 'min:1000'],
            'earnest_money_deposit' => ['nullable', 'numeric', 'min:0'],
            'down_payment_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'string', 'in:cash,conventional,fha,va,other'],
            'closing_date' => ['required', 'date', 'after:today'],
            'occupancy_type' => ['nullable', 'string', 'in:at_closing,days_after_closing'],
            'occupancy_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'seller_concessions' => ['nullable', 'numeric', 'min:0'],
            'contingencies' => ['nullable', 'array'],
            'deadline' => ['nullable', 'date', 'after:now'],
            'additional_terms' => ['nullable', 'string', 'max:5000'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'buyer_email' => ['nullable', 'email', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:32'],
            'has_agent_representation' => ['nullable', 'boolean'],
            'agent_name' => ['nullable', 'string', 'max:255'],
            'agent_email' => ['nullable', 'email', 'max:255'],
            'agent_phone' => ['nullable', 'string', 'max:32'],
            'documents' => ['nullable', 'array'],
            'documents.*.type' => ['required_with:documents', 'string', 'in:pre_approval_letter,proof_of_funds,purchase_agreement,other'],
            'documents.*.file' => ['required_with:documents', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:15360'],
        ]);

        $documents = [];
        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $index => $docItem) {
                $docType = $request->input("documents.{$index}.type", 'pre_approval_letter');
                $documents[] = [
                    'file' => $docItem['file'] ?? $docItem,
                    'type' => $docType,
                ];
            }
        }

        $offer = $this->offerService->submitOffer($listing, $request->user(), $validated, $documents);

        return response()->json([
            'message' => 'Offer successfully submitted.',
            'offer' => $offer,
        ], 201);
    }

    /**
     * Get offers submitted by the current authenticated buyer.
     */
    public function myOffers(Request $request): JsonResponse
    {
        $offers = ListingOffer::where('buyer_id', $request->user()->id)
            ->with(['listing:id,reference_code,address,city,state,zip,status', 'actions', 'documents'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($offers);
    }

    /**
     * Get offer details for the buyer.
     */
    public function showBuyerOffer(Request $request, ListingOffer $offer): JsonResponse
    {
        $isAdmin = ($request->user()->role === 'admin' || (method_exists($request->user(), 'hasRole') && $request->user()->roles()->where('name', 'admin')->exists()));
        if ($offer->buyer_id !== $request->user()->id && !$isAdmin) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json($offer->load([
            'listing:id,reference_code,address,city,state,zip,status,user_id',
            'actions.user:id,name,first_name,last_name',
            'documents',
        ]));
    }

    /**
     * Get all offers received on a listing (Seller / Authorized Agent).
     */
    public function listingOffers(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('view', $listing);

        $offers = $listing->offers()
            ->with(['buyer:id,name,first_name,last_name,email,phone', 'actions.user:id,name', 'documents'])
            ->get();

        return response()->json([
            'listing_id' => $listing->id,
            'listing_status' => $listing->status,
            'offers' => $offers,
        ]);
    }

    /**
     * Get offer details for seller.
     */
    public function showSellerOffer(Request $request, ListingOffer $offer): JsonResponse
    {
        Gate::authorize('view', $offer->listing);

        return response()->json($offer->load([
            'listing',
            'buyer:id,name,first_name,last_name,email,phone',
            'actions.user:id,name',
            'documents',
        ]));
    }

    /**
     * Counter an offer (Seller or Buyer).
     */
    public function counter(Request $request, ListingOffer $offer): JsonResponse
    {
        $user = $request->user();
        $isSeller = ($offer->listing->user_id === $user->id || $user->hasRole('admin'));
        $isBuyer = ($offer->buyer_id === $user->id);

        if (!$isSeller && !$isBuyer) {
            return response()->json(['message' => 'Unauthorized to counter this offer.'], 403);
        }

        $validated = $request->validate([
            'offer_amount' => ['required', 'numeric', 'min:1000'],
            'earnest_money_deposit' => ['nullable', 'numeric', 'min:0'],
            'seller_concessions' => ['nullable', 'numeric', 'min:0'],
            'closing_date' => ['nullable', 'date', 'after:today'],
            'deadline' => ['nullable', 'date', 'after:now'],
            'additional_terms' => ['nullable', 'string', 'max:5000'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $updatedOffer = $this->offerService->counterOffer($offer, $user, $validated);

        return response()->json([
            'message' => 'Counter-offer successfully submitted.',
            'offer' => $updatedOffer,
        ]);
    }

    /**
     * Accept an offer or counter-offer.
     */
    public function accept(Request $request, ListingOffer $offer): JsonResponse
    {
        $user = $request->user();
        $isSeller = ($offer->listing->user_id === $user->id || $user->hasRole('admin'));
        $isBuyer = ($offer->buyer_id === $user->id);

        if (!$isSeller && !$isBuyer) {
            return response()->json(['message' => 'Unauthorized to accept this offer.'], 403);
        }

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $acceptedOffer = $this->offerService->acceptOffer($offer, $user, $validated['comment'] ?? null);

        return response()->json([
            'message' => 'Offer accepted successfully! Listing is now under contract.',
            'offer' => $acceptedOffer,
        ]);
    }

    /**
     * Reject an offer (Seller).
     */
    public function reject(Request $request, ListingOffer $offer): JsonResponse
    {
        Gate::authorize('update', $offer->listing);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $rejectedOffer = $this->offerService->rejectOffer($offer, $request->user(), $validated['reason'] ?? null);

        return response()->json([
            'message' => 'Offer declined.',
            'offer' => $rejectedOffer,
        ]);
    }

    /**
     * Withdraw an offer (Buyer).
     */
    public function withdraw(Request $request, ListingOffer $offer): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $withdrawnOffer = $this->offerService->withdrawOffer($offer, $request->user(), $validated['reason'] ?? null);

        return response()->json([
            'message' => 'Offer withdrawn.',
            'offer' => $withdrawnOffer,
        ]);
    }

    /**
     * Securely download an offer attachment document.
     */
    public function downloadDocument(Request $request, ListingOffer $offer, ListingOfferDocument $document): StreamedResponse|JsonResponse
    {
        $user = $request->user();
        $isAdmin = ($user->role === 'admin' || (method_exists($user, 'hasRole') && $user->roles()->where('name', 'admin')->exists()));
        $isParticipant = ($offer->buyer_id === $user->id || $offer->listing->user_id === $user->id || $isAdmin);

        if (!$isParticipant) {
            return response()->json(['message' => 'Unauthorized to access this document.'], 403);
        }

        if ($document->listing_offer_id !== $offer->id) {
            return response()->json(['message' => 'Document not associated with this offer.'], 404);
        }

        if (!Storage::disk('local')->exists($document->path)) {
            return response()->json(['message' => 'File not found on storage.'], 404);
        }

        return Storage::disk('local')->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    /**
     * Admin view of all offers with search, filters, and stats.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = ListingOffer::with([
            'listing:id,reference_code,address,city,state,zip,status,user_id',
            'listing.user:id,name,email,first_name,last_name',
            'buyer:id,name,email,first_name,last_name',
            'documents',
            'actions.user:id,name,first_name,last_name',
        ]);

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $status = $request->query('status');
            if ($status === 'countered') {
                $query->whereIn('status', ['countered_by_seller', 'countered_by_buyer']);
            } elseif ($status === 'rejected') {
                $query->whereIn('status', ['rejected', 'rejected_under_contract']);
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->filled('listing_id')) {
            $query->where('listing_id', $request->query('listing_id'));
        }

        if ($request->filled('search')) {
            $search = '%' . trim($request->query('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('reference_code', 'like', $search)
                  ->orWhere('buyer_name', 'like', $search)
                  ->orWhere('buyer_email', 'like', $search)
                  ->orWhere('agent_name', 'like', $search)
                  ->orWhereHas('buyer', function ($bq) use ($search) {
                      $bq->where('name', 'like', $search)
                         ->orWhere('email', 'like', $search)
                         ->orWhere('first_name', 'like', $search)
                         ->orWhere('last_name', 'like', $search);
                  })
                  ->orWhereHas('listing', function ($lq) use ($search) {
                      $lq->where('address', 'like', $search)
                         ->orWhere('city', 'like', $search)
                         ->orWhere('zip', 'like', $search)
                         ->orWhere('reference_code', 'like', $search);
                  });
            });
        }

        $perPage = (int) $request->query('per_page', 15);
        $offers = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Include summary metrics
        $metrics = [
            'total_offers' => ListingOffer::count(),
            'active_offers' => ListingOffer::whereIn('status', ['submitted', 'countered_by_seller', 'countered_by_buyer'])->count(),
            'accepted_offers' => ListingOffer::where('status', 'accepted')->count(),
            'accepted_volume' => (float) ListingOffer::where('status', 'accepted')->sum('offer_amount'),
            'rejected_offers' => ListingOffer::whereIn('status', ['rejected', 'rejected_under_contract', 'withdrawn', 'expired'])->count(),
        ];

        return response()->json([
            'offers' => $offers,
            'metrics' => $metrics,
        ]);
    }

    /**
     * Admin view single offer detail.
     */
    public function adminShow(Request $request, ListingOffer $offer): JsonResponse
    {
        return response()->json($offer->load([
            'listing:id,reference_code,address,city,state,zip,status,user_id',
            'listing.user:id,name,first_name,last_name,email',
            'buyer:id,name,email,first_name,last_name',
            'actions.user:id,name,first_name,last_name',
            'documents',
        ]));
    }
}
