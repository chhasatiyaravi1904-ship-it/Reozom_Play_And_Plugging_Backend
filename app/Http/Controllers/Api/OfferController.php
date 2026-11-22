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
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OfferController extends Controller
{
    public function __construct(
        protected OfferService $offerService
    ) {}

    /**
     * Get initial configuration and prefilled metadata for the Send Offer view.
     * Replicates Reozom OffersController@getSendOffer
     */
    public function sendOfferConfig(Request $request, Listing $listing): JsonResponse
    {
        // Only for listings this user may see (published, or their own): no draft details by ID.
        Gate::authorize('view', $listing);

        $buyer = $request->user();
        $isListingOwner = ($listing->user_id === $buyer->id);

        $hasOpenOffer = ListingOffer::where('listing_id', $listing->id)
            ->where('buyer_id', $buyer->id)
            ->whereIn('status', [
                ListingOffer::STATUS_SUBMITTED,
                ListingOffer::STATUS_COUNTERED_BY_SELLER,
                ListingOffer::STATUS_COUNTERED_BY_BUYER,
            ])
            ->exists();

        $amount = (float) ($listing->details?->price ?? $listing->price ?? 0);
        
        // Reozom mortgage formula for daily fee:
        $months = 360;
        $interestRate = 4.5 / 1200;
        $feePerDay = 50;
        if ($amount > 0) {
            $pow = pow(1 + $interestRate, $months);
            $rawFee = (($amount * ($interestRate * $pow)) / ($pow - 1)) / 30;
            $feePerDay = max(10, round((intval($rawFee) / 10) * 10));
        }

        $buyerRole = ($buyer->role instanceof \App\Enums\UserRole) ? $buyer->role->value : (string) $buyer->role;
        $isAgent = ($buyerRole === 'agent');
        $yearBuilt = (int) ($listing->propertyInformation?->year_built ?? $listing->year_built ?? 2000);
        $hasWell = (bool) ($listing->has_well ?? false);
        $hasSeptic = (bool) ($listing->has_septic ?? false);

        $states = [
            ['code' => 'MI', 'name' => 'Michigan'],
            ['code' => 'OH', 'name' => 'Ohio'],
            ['code' => 'FL', 'name' => 'Florida'],
            ['code' => 'IN', 'name' => 'Indiana'],
            ['code' => 'IL', 'name' => 'Illinois'],
            ['code' => 'WI', 'name' => 'Wisconsin'],
            ['code' => 'TX', 'name' => 'Texas'],
            ['code' => 'CA', 'name' => 'California'],
            ['code' => 'NY', 'name' => 'New York'],
            ['code' => 'GA', 'name' => 'Georgia'],
            ['code' => 'NC', 'name' => 'North Carolina'],
            ['code' => 'PA', 'name' => 'Pennsylvania'],
            ['code' => 'TN', 'name' => 'Tennessee'],
            ['code' => 'AZ', 'name' => 'Arizona'],
            ['code' => 'CO', 'name' => 'Colorado'],
        ];

        return response()->json([
            'listing' => [
                'id' => $listing->id,
                'reference_code' => $listing->reference_code,
                'address' => $listing->address,
                'city' => $listing->city,
                'state' => $listing->state,
                'zip' => $listing->zip,
                'full_address' => trim("{$listing->address}, {$listing->city}, {$listing->state} {$listing->zip}"),
                'price' => $amount,
                'year_built' => $yearBuilt,
                'has_well' => $hasWell,
                'has_septic' => $hasSeptic,
                'user_id' => $listing->user_id,
            ],
            'buyer' => [
                'id' => $buyer->id,
                'name' => $buyer->name,
                'first_name' => $buyer->first_name ?: explode(' ', $buyer->name)[0],
                'last_name' => $buyer->last_name ?: (explode(' ', $buyer->name)[1] ?? ''),
                'email' => $buyer->email,
                'phone' => $buyer->phone,
                'role' => $buyerRole,
                'is_agent' => $isAgent,
            ],
            'is_listing_owner' => $isListingOwner,
            'has_open_offer' => $hasOpenOffer,
            'fee_per_day' => $feePerDay,
            'home_warranty_price' => 499,
            'appliances' => [
                ['id' => 'Dryer', 'name' => 'Dryer'],
                ['id' => 'Freezer', 'name' => 'Freezer'],
                ['id' => 'Refrigerator', 'name' => 'Refrigerator'],
                ['id' => 'Stove', 'name' => 'Stove'],
                ['id' => 'Washing Machine', 'name' => 'Washing Machine'],
                ['id' => 'Microwave', 'name' => 'Microwave'],
                ['id' => 'Other', 'name' => 'Other'],
            ],
            'payment_methods' => [
                ['id' => 'conventional', 'name' => 'Conventional Mortgage', 'requires_financing_dates' => true],
                ['id' => 'cash', 'name' => 'Cash', 'requires_financing_dates' => false],
                ['id' => 'fha', 'name' => 'FHA Loan', 'requires_financing_dates' => true],
                ['id' => 'va', 'name' => 'VA Loan', 'requires_financing_dates' => true],
                ['id' => 'land_contract', 'name' => 'Land Contract', 'requires_financing_dates' => true],
                ['id' => 'other', 'name' => 'Other', 'requires_financing_dates' => true],
            ],
            'deadlines' => [
                ['hours' => 24, 'label' => '24 Hours'],
                ['hours' => 48, 'label' => '48 Hours (Standard)'],
                ['hours' => 72, 'label' => '72 Hours (3 Days)'],
                ['hours' => 120, 'label' => '120 Hours (5 Days)'],
            ],
            'states' => $states,
        ]);
    }

    /**
     * Preview example Michigan Purchase Agreement PDF.
     * Replicates Reozom OffersController@purchaseAgreementExample
     */
    public function examplePurchaseAgreement(Request $request, Listing $listing)
    {
        Gate::authorize('view', $listing);

        $mockOffer = new ListingOffer([
            'reference_code' => 'SAMPLE-PA',
            'offer_amount' => $listing->details?->price ?? $listing->price ?? 350000,
            'earnest_money_deposit' => 5000,
            'seller_concessions' => 0,
            'closing_amount_type' => 2,
            'closing_date' => now()->addDays(30),
            'payment_method' => 'conventional',
            'occupancy_type' => 'at_closing',
            'status' => 'SAMPLE DRAFT',
            'additional_terms' => 'This is a sample Michigan Residential Real Estate Purchase Agreement preview illustrating standard statutory clauses.',
            'created_at' => now(),
        ]);
        $mockOffer->setRelation('listing', $listing);
        $mockOffer->setRelation('buyer', $request->user());
        $mockOffer->setRelation('purchasers', collect());

        $html = $this->renderPurchaseAgreementHtml($mockOffer);

        if (class_exists(Pdf::class)) {
            $pdf = Pdf::loadHTML($html)
                ->setPaper('letter', 'portrait')
                ->setWarnings(false);

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="Example_Purchase_Agreement.pdf"',
            ]);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    /**
     * Submit an offer on a listing (Buyer or Buyer's Agent).
     * Replicates Reozom OffersController@postSendOffer
     */
    public function store(Request $request, Listing $listing): JsonResponse
    {
        if ($request->has('contingencies') && is_string($request->input('contingencies'))) {
            $decoded = json_decode($request->input('contingencies'), true);
            if (is_array($decoded)) {
                $request->merge(['contingencies' => $decoded]);
            }
        }
        if ($request->has('purchasers') && is_string($request->input('purchasers'))) {
            $decoded = json_decode($request->input('purchasers'), true);
            if (is_array($decoded)) {
                $request->merge(['purchasers' => $decoded]);
            }
        }
        if ($request->has('appliances') && is_string($request->input('appliances'))) {
            $decoded = json_decode($request->input('appliances'), true);
            if (is_array($decoded)) {
                $request->merge(['appliances' => $decoded]);
            }
        }

        $validated = $request->validate([
            'offer_amount' => ['required', 'numeric', 'min:1000'],
            'direct_buyer_rebate' => ['nullable', 'numeric', 'min:0'],
            'earnest_money_deposit' => ['nullable', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'down_payment_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'string', 'in:cash,conventional,fha,va,land_contract,other'],
            'payment_method_id' => ['nullable'],
            'mortgage_application_provided_by' => ['nullable', 'date'],
            'loan_approval_provided_by' => ['nullable', 'date'],
            'closing_date' => ['required', 'date', 'after:today'],
            'occupancy_option' => ['nullable', 'in:1,2'],
            'occupancy_type' => ['nullable', 'string', 'in:at_closing,days_after_closing'],
            'occupancy_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'days_after_closing' => ['nullable', 'integer', 'min:0', 'max:180'],
            'fee_per_day' => ['nullable', 'numeric', 'min:0'],
            'seller_concessions' => ['nullable', 'numeric', 'min:0'],
            'closing_amount' => ['nullable', 'numeric', 'min:0'],
            'closing_type' => ['nullable', 'in:1,2'],
            'closing_amount_type' => ['nullable', 'integer', 'in:1,2'],
            'commission' => ['nullable', 'numeric', 'min:0'],
            'commission_type' => ['nullable', 'integer', 'in:1,2'],
            'home_warranty_included' => ['nullable'],
            'home_warranty_price' => ['nullable', 'numeric', 'min:0'],
            'buyers_home_address_under_contract' => ['nullable', 'string', 'max:500'],
            'buyers_home_address' => ['nullable', 'string', 'max:255'],
            'buyers_home_unit_designator' => ['nullable'],
            'buyers_home_unit_number' => ['nullable', 'string', 'max:50'],
            'buyers_home_city' => ['nullable', 'string', 'max:100'],
            'buyers_home_state' => ['nullable', 'string', 'max:100'],
            'buyers_home_zip' => ['nullable', 'string', 'max:20'],
            'contingencies' => ['nullable', 'array'],
            'oc_property_inspection' => ['nullable'],
            'oc_property_inspection_days' => ['nullable', 'integer'],
            'oc_property_paint_inspection' => ['nullable'],
            'oc_property_paint_inspection_days' => ['nullable', 'integer'],
            'oc_sale_buyers_home' => ['nullable'],
            'any_offer_accepted' => ['nullable'],
            'anticipated_close_date' => ['nullable', 'date'],
            'buyers_home_under_contract' => ['nullable', 'date'],
            'oc_well_inspection' => ['nullable'],
            'oc_well_payment' => ['nullable', 'string'],
            'oc_well_days' => ['nullable', 'integer'],
            'oc_septic_inspection' => ['nullable'],
            'oc_septic_payment' => ['nullable', 'string'],
            'oc_septic_days' => ['nullable', 'integer'],
            'oc_survey' => ['nullable'],
            'oc_review_buyers_attorney' => ['nullable'],
            'appliances' => ['nullable'],
            'other_appliances' => ['nullable'],
            'deadline' => ['nullable'],
            'additional_terms' => ['nullable', 'string', 'max:5000'],
            'other_conditions' => ['nullable', 'string', 'max:5000'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'message' => ['nullable', 'string', 'max:2000'],
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'buyer_email' => ['nullable', 'email', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:32'],
            'has_agent_representation' => ['nullable', 'boolean'],
            'agent_name' => ['nullable', 'string', 'max:255'],
            'agent_email' => ['nullable', 'email', 'max:255'],
            'agent_phone' => ['nullable', 'string', 'max:32'],
            'main_purchaser_first_name' => ['nullable', 'string', 'max:100'],
            'main_purchaser_last_name' => ['nullable', 'string', 'max:100'],
            'main_purchaser_email' => ['nullable', 'email', 'max:150'],
            'main_purchaser_address' => ['nullable', 'string', 'max:255'],
            'main_purchaser_city' => ['nullable', 'string', 'max:100'],
            'main_purchaser_state' => ['nullable', 'string', 'max:50'],
            'main_purchaser_zip' => ['nullable', 'string', 'max:20'],
            'main_purchaser_non_us_citizen' => ['nullable', 'boolean'],
            'purchasers' => ['nullable', 'array'],
            'purchasers.*.first_name' => ['required_with:purchasers', 'string', 'max:100'],
            'purchasers.*.last_name' => ['nullable', 'string', 'max:100'],
            'purchasers.*.email' => ['nullable', 'email', 'max:150'],
            'purchasers.*.non_us_citizen' => ['nullable', 'boolean'],
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

        // Direct file uploads for pre-approval letter or proof of funds
        if ($request->hasFile('document')) {
            $documents[] = [
                'file' => $request->file('document'),
                'type' => 'pre_approval_letter',
            ];
        }
        if ($request->hasFile('additional_document')) {
            $documents[] = [
                'file' => $request->file('additional_document'),
                'type' => 'other',
            ];
        }

        $offer = $this->offerService->submitOffer($listing, $request->user(), $validated, $documents);

        return response()->json([
            'message' => 'Offer successfully submitted.',
            'offer' => $offer,
        ], 201);
    }

    /**
     * Get offers separated by user involvement and role (Reozom multi-role offer dashboard).
     * Replicates Reozom OffersController@waitingForYourAnswer, sentOffers, receivedOffers.
     */
    public function offersByInvolvement(Request $request): JsonResponse
    {
        $user = $request->user();
        $involvement = $request->query('involvement', 'action_required'); // action_required, received, sent, all
        $status = $request->query('status');
        $search = $request->query('search');
        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        // Listings this user owns: they can act (accept/counter/reject) on these offers.
        $userListingIds = Listing::where('user_id', $user->id)->pluck('id');
        // "Received" also covers an agent's clients: listings on one of the agent's plans (view only).
        $receivedListingIds = $this->receivedListingIds($user);

        // Base query for all offers involving the user (or all if admin)
        $query = ListingOffer::with([
            'listing:id,reference_code,address,city,state,zip,status,user_id',
            'listing.user:id,name,first_name,last_name,email,role,is_active',
            'buyer:id,name,first_name,last_name,email,phone',
            'actions.user:id,name,first_name,last_name',
            'documents',
            'purchasers',
        ]);

        // Calculate counts for each involvement category
        $actionRequiredCount = ListingOffer::where(function ($q) use ($user, $userListingIds) {
            // As Seller: new offer submitted or buyer counter received
            $q->whereIn('listing_id', $userListingIds)
              ->whereIn('status', [ListingOffer::STATUS_SUBMITTED, ListingOffer::STATUS_COUNTERED_BY_BUYER]);
        })->orWhere(function ($q) use ($user) {
            // As Buyer: seller counter received
            $q->where('buyer_id', $user->id)
              ->where('status', ListingOffer::STATUS_COUNTERED_BY_SELLER);
        })->count();

        $receivedCount = ListingOffer::whereIn('listing_id', $receivedListingIds)->count();
        $sentCount = ListingOffer::where('buyer_id', $user->id)->count();

        if ($user->isAdmin()) {
            $totalCount = ListingOffer::count();
        } else {
            $totalCount = ListingOffer::where(function ($q) use ($user, $receivedListingIds) {
                $q->where('buyer_id', $user->id)
                  ->orWhereIn('listing_id', $receivedListingIds);
            })->count();
        }

        // Apply involvement filter
        if ($involvement === 'action_required') {
            $query->where(function ($q) use ($user, $userListingIds) {
                $q->where(function ($sq) use ($userListingIds) {
                    $sq->whereIn('listing_id', $userListingIds)
                       ->whereIn('status', [ListingOffer::STATUS_SUBMITTED, ListingOffer::STATUS_COUNTERED_BY_BUYER]);
                })->orWhere(function ($sq) use ($user) {
                    $sq->where('buyer_id', $user->id)
                       ->where('status', ListingOffer::STATUS_COUNTERED_BY_SELLER);
                });
            });
        } elseif ($involvement === 'received') {
            $query->whereIn('listing_id', $receivedListingIds);
        } elseif ($involvement === 'sent') {
            $query->where('buyer_id', $user->id);
        } else { // 'all'
            if (!$user->isAdmin()) {
                $query->where(function ($q) use ($user, $receivedListingIds) {
                    $q->where('buyer_id', $user->id)
                      ->orWhereIn('listing_id', $receivedListingIds);
                });
            }
        }

        // Apply status filter if provided
        if (!empty($status) && $status !== 'all') {
            if ($status === 'countered') {
                $query->whereIn('status', [ListingOffer::STATUS_COUNTERED_BY_SELLER, ListingOffer::STATUS_COUNTERED_BY_BUYER]);
            } else {
                $query->where('status', $status);
            }
        }

        // Apply search if provided
        if (!empty($search)) {
            $s = '%' . trim($search) . '%';
            $query->where(function ($q) use ($s) {
                $q->where('reference_code', 'like', $s)
                  ->orWhere('buyer_name', 'like', $s)
                  ->orWhere('agent_name', 'like', $s)
                  ->orWhereHas('listing', function ($lq) use ($s) {
                      $lq->where('address', 'like', $s)
                         ->orWhere('city', 'like', $s)
                         ->orWhere('reference_code', 'like', $s);
                  });
            });
        }

        $paginated = $query->orderBy('updated_at', 'desc')->paginate($perPage);

        // Decorate each offer with user's involvement metadata
        $paginated->getCollection()->transform(function (ListingOffer $offer) use ($user, $receivedListingIds) {
            $isBuyer = ($offer->buyer_id === $user->id);
            $isSeller = ($offer->listing && $offer->listing->user_id === $user->id);

            $myRole = 'other';
            if ($isSeller) {
                $myRole = 'seller';
            } elseif ($isBuyer) {
                $myRole = 'buyer';
            } elseif ($receivedListingIds->contains($offer->listing_id)) {
                $myRole = 'agent'; // the listing uses one of this agent's plans (view only)
            } elseif ($user->isAdmin()) {
                $myRole = 'admin';
            }

            $isActionRequired = false;
            if ($isSeller && in_array($offer->status, [ListingOffer::STATUS_SUBMITTED, ListingOffer::STATUS_COUNTERED_BY_BUYER], true)) {
                $isActionRequired = true;
            } elseif ($isBuyer && $offer->status === ListingOffer::STATUS_COUNTERED_BY_SELLER) {
                $isActionRequired = true;
            }

            $offer->setAttribute('my_role', $myRole);
            $offer->setAttribute('is_action_required', $isActionRequired);

            return $offer;
        });

        return response()->json([
            'offers' => $paginated,
            'counts' => [
                'action_required' => $actionRequiredCount,
                'received' => $receivedCount,
                'sent' => $sentCount,
                'total' => $totalCount,
            ],
            'involvement' => $involvement,
        ]);
    }

    /**
     * Listings whose offers this user receives: their own listings, plus (for an
     * agent) listings created on one of their plans for a client.
     */
    private function receivedListingIds(\App\Models\User $user): \Illuminate\Support\Collection
    {
        return Listing::where('user_id', $user->id)
            ->when($user->isAgent(), fn ($q) => $q->orWhereHas(
                'servicePackage',
                fn ($sp) => $sp->where('agent_id', $user->id)
            ))
            ->pluck('id');
    }

    /**
     * Who may read all offers on a listing: its owner, the agent whose plan it
     * uses, or an admin. (Not "anyone who can view the listing": published
     * listings are visible to every user, and offers hold other buyers' data.)
     */
    private function canReadListingOffers(\App\Models\User $user, ?Listing $listing): bool
    {
        if (! $listing) {
            return $user->isAdmin();
        }

        return $user->isAdmin()
            || $listing->user_id === $user->id
            || ($user->isAgent() && $listing->servicePackage?->agent_id === $user->id);
    }

    /**
     * Unified offer details endpoint for any involved participant (Buyer, Seller, the listing's Agent, or Admin).
     */
    public function showOffer(Request $request, ListingOffer $offer): JsonResponse
    {
        $user = $request->user();
        $isBuyer = ($offer->buyer_id === $user->id);
        $isParticipant = $isBuyer || $this->canReadListingOffers($user, $offer->listing);

        if (!$isParticipant) {
            return response()->json(['message' => 'Unauthorized to view this offer.'], 403);
        }

        $isSeller = ($offer->listing && $offer->listing->user_id === $user->id);

        $myRole = $isSeller ? 'seller' : ($isBuyer ? 'buyer' : ($user->isAdmin() ? 'admin' : 'agent'));
        $isActionRequired = ($isSeller && in_array($offer->status, ['submitted', 'countered_by_buyer'])) ||
                            ($isBuyer && $offer->status === 'countered_by_seller');

        $offer->load([
            'listing:id,reference_code,address,city,state,zip,status,user_id',
            'listing.user:id,name,first_name,last_name,email,phone',
            'buyer:id,name,first_name,last_name,email,phone',
            'actions.user:id,name,first_name,last_name',
            'documents',
            'purchasers',
        ]);

        $offer->setAttribute('my_role', $myRole);
        $offer->setAttribute('is_action_required', $isActionRequired);

        return response()->json($offer);
    }

    /**
     * Get offers submitted by the current authenticated buyer.
     */
    public function myOffers(Request $request): JsonResponse
    {
        $offers = ListingOffer::where('buyer_id', $request->user()->id)
            ->with([
                'listing:id,reference_code,address,city,state,zip,status',
                'actions',
                'documents',
                'purchasers',
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($offers);
    }

    /**
     * Get offer details for the buyer.
     */
    public function showBuyerOffer(Request $request, ListingOffer $offer): JsonResponse
    {
        $isAdmin = $request->user()->isAdmin();
        if ($offer->buyer_id !== $request->user()->id && !$isAdmin) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json($offer->load([
            'listing:id,reference_code,address,city,state,zip,status,user_id',
            'actions.user:id,name,first_name,last_name',
            'documents',
            'purchasers',
        ]));
    }

    /**
     * Get all offers received on a listing (Seller / Authorized Agent).
     */
    public function listingOffers(Request $request, Listing $listing): JsonResponse
    {
        abort_unless($this->canReadListingOffers($request->user(), $listing), 403, 'You are not allowed to view the offers on this listing.');

        $offers = $listing->offers()
            ->with([
                'buyer:id,name,first_name,last_name,email,phone',
                'actions.user:id,name',
                'documents',
                'purchasers',
            ])
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
        abort_unless($this->canReadListingOffers($request->user(), $offer->listing), 403, 'You are not allowed to view this offer.');

        return response()->json($offer->load([
            'listing',
            'buyer:id,name,first_name,last_name,email,phone',
            'actions.user:id,name',
            'documents',
            'purchasers',
        ]));
    }

    /**
     * Counter an offer (Seller or Buyer).
     * Replicates Reozom OffersController@postSendCounteroffer
     */
    public function counter(Request $request, ListingOffer $offer): JsonResponse
    {
        $user = $request->user();
        $isSeller = ($offer->listing->user_id === $user->id || $user->isAdmin());
        $isBuyer = ($offer->buyer_id === $user->id);

        if (!$isSeller && !$isBuyer) {
            return response()->json(['message' => 'Unauthorized to counter this offer.'], 403);
        }

        if ($request->has('contingencies') && is_string($request->input('contingencies'))) {
            $decoded = json_decode($request->input('contingencies'), true);
            if (is_array($decoded)) {
                $request->merge(['contingencies' => $decoded]);
            }
        }

        $validated = $request->validate([
            'offer_amount' => ['required', 'numeric', 'min:1000'],
            'earnest_money_deposit' => ['nullable', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'down_payment_amount' => ['nullable', 'numeric', 'min:0'],
            'seller_concessions' => ['nullable', 'numeric', 'min:0'],
            'closing_amount' => ['nullable', 'numeric', 'min:0'],
            'closing_amount_type' => ['nullable', 'integer', 'in:1,2'],
            'closing_date' => ['nullable', 'date', 'after:today'],
            'occupancy_type' => ['nullable', 'string', 'in:at_closing,days_after_closing'],
            'occupancy_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'fee_per_day' => ['nullable', 'numeric', 'min:0'],
            'commission' => ['nullable', 'numeric', 'min:0'],
            'commission_type' => ['nullable', 'integer', 'in:1,2'],
            'home_warranty_included' => ['nullable', 'boolean'],
            'contingencies' => ['nullable', 'array'],
            'deadline' => ['nullable'],
            'additional_terms' => ['nullable', 'string', 'max:5000'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $updatedOffer = $this->offerService->counterOffer($offer, $user, $validated);

        return response()->json([
            'message' => 'Counter-offer successfully submitted.',
            'offer' => $updatedOffer,
        ]);
    }

    /**
     * Accept an offer or counter-offer.
     * Replicates Reozom OffersController@postAccept & postAcceptCounterOffer
     */
    public function accept(Request $request, ListingOffer $offer): JsonResponse
    {
        $user = $request->user();
        $isSeller = ($offer->listing->user_id === $user->id || $user->isAdmin());
        $isBuyer = ($offer->buyer_id === $user->id);

        if (!$isSeller && !$isBuyer) {
            return response()->json(['message' => 'Unauthorized to accept this offer.'], 403);
        }

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $acceptedOffer = $this->offerService->acceptOffer(
            $offer,
            $user,
            $validated['comment'] ?? ($validated['message'] ?? null)
        );

        return response()->json([
            'message' => 'Offer accepted successfully! Listing is now under contract.',
            'offer' => $acceptedOffer,
        ]);
    }

    /**
     * Reject / decline an offer (Seller or Buyer).
     * Replicates Reozom OffersController@postDecline & postDeclineCounterOffer
     */
    public function reject(Request $request, ListingOffer $offer): JsonResponse
    {
        $user = $request->user();
        $isSeller = ($offer->listing->user_id === $user->id || $user->isAdmin());
        $isBuyer = ($offer->buyer_id === $user->id);

        if (!$isSeller && !$isBuyer) {
            return response()->json(['message' => 'Unauthorized to decline this offer.'], 403);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $rejectedOffer = $this->offerService->rejectOffer(
            $offer,
            $user,
            $validated['reason'] ?? ($validated['message'] ?? null)
        );

        return response()->json([
            'message' => 'Offer declined.',
            'offer' => $rejectedOffer,
        ]);
    }

    /**
     * Withdraw / cancel an offer (Buyer or Seller).
     * Replicates Reozom OffersController@cancel & postCancelCounterOffer
     */
    public function withdraw(Request $request, ListingOffer $offer): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $withdrawnOffer = $this->offerService->withdrawOffer(
            $offer,
            $request->user(),
            $validated['reason'] ?? ($validated['message'] ?? null)
        );

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
        $isParticipant = ($offer->buyer_id === $user->id || $this->canReadListingOffers($user, $offer->listing));

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
     * Download or view the official Purchase Agreement Contract PDF for an offer.
     */
    public function downloadContract(Request $request, ListingOffer $offer)
    {
        $user = $request->user();
        $isParticipant = ($offer->buyer_id === $user->id || $this->canReadListingOffers($user, $offer->listing));

        if (!$isParticipant) {
            return response()->json(['message' => 'Unauthorized to access this contract.'], 403);
        }

        $html = $this->renderPurchaseAgreementHtml($offer);

        $disposition = $request->query('disposition', 'inline');
        if (!in_array($disposition, ['inline', 'attachment'], true)) {
            $disposition = 'inline';
        }

        if (class_exists(Pdf::class)) {
            $pdf = Pdf::loadHTML($html)
                ->setPaper('letter', 'portrait')
                ->setWarnings(false);

            $filename = "Purchase_Agreement_{$offer->reference_code}.pdf";

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
            ]);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    /**
     * Render a standard Reozom Purchase Agreement Contract HTML.
     */
    /**
     * One signature line per purchase agreement signer, in signing order. The white "$sN" text is
     * EunoDoc's placement anchor for signer N (same layout as the listing documents).
     *
     * @param  array<int, array{name: string, email: string, role: string}>  $signers
     */
    public function purchaseAgreementSignatureLines(array $signers): string
    {
        $roleLabels = [
            'listing_agent' => 'Listing Agent Signature',
            'selling_agent' => 'Selling Agent Signature',
            'buyer' => 'Purchaser Signature',
            'seller' => 'Seller Signature',
            'additional_signer' => 'Seller Signature',
        ];

        $cell = fn (int $number, array $signer): string => ListingDocumentController::signatureCellHtml(
            $number, $roleLabels[$signer['role'] ?? ''] ?? 'Signature', $signer
        );

        $rows = '';
        foreach (array_chunk($signers, 2) as $rowIndex => $pair) {
            $cells = [];
            foreach ($pair as $i => $signer) {
                $cells[] = $cell($rowIndex * 2 + $i + 1, $signer);
            }
            $rows .= '<tr>'.implode('<td style="width:12%;"></td>', $cells).'</tr>'
                .'<tr><td colspan="3" style="height:18px;"></td></tr>';
        }

        return '<table style="width:100%;border-collapse:collapse;margin-top:12px;">'.$rows.'</table>';
    }

    private function renderPurchaseAgreementHtml(ListingOffer $offer): string
    {
        $listing = $offer->listing;
        $buyer = $offer->buyer;
        $seller = $listing->user;

        $address = htmlspecialchars(trim("{$listing->address}, {$listing->city}, {$listing->state} {$listing->zip}"));
        $buyerName = htmlspecialchars($offer->buyer_name ?? ($buyer ? trim("{$buyer->first_name} {$buyer->last_name}") : 'Buyer'));
        $sellerName = htmlspecialchars($seller ? trim("{$seller->first_name} {$seller->last_name}") : 'Seller');
        $offerCode = htmlspecialchars($offer->reference_code);
        $listingCode = htmlspecialchars($listing->reference_code);

        $price = number_format((float) $offer->offer_amount, 2);
        $deposit = number_format((float) ($offer->earnest_money_deposit ?? 0), 2);
        $concessions = number_format((float) ($offer->seller_concessions ?? 0), 2);
        $closingDate = $offer->closing_date ? $offer->closing_date->format('F j, Y') : 'TBD';
        $paymentMethod = htmlspecialchars(ucfirst(str_replace('_', ' ', $offer->payment_method ?? 'Conventional Mortgage')));
        $occupancy = $offer->occupancy_type === 'days_after_closing'
            ? htmlspecialchars("{$offer->occupancy_days} days after closing at $" . number_format((float) $offer->fee_per_day, 2) . "/day")
            : 'At closing';

        $isAccepted = $offer->status === 'accepted';
        $statusLabel = strtoupper(str_replace('_', ' ', $offer->status));
        $date = $offer->created_at ? $offer->created_at->format('F j, Y') : now()->format('F j, Y');
        $signatureBlock = $this->purchaseAgreementSignatureLines($offer->eSignSigners());

        // Purchasers list
        $purchasersHtml = '';
        foreach ($offer->purchasers as $p) {
            $pName = htmlspecialchars(trim("{$p->first_name} {$p->last_name}"));
            $pEmail = htmlspecialchars($p->email);
            $purchasersHtml .= "<div>&bull; {$pName} ({$pEmail})</div>";
        }
        if (empty($purchasersHtml)) {
            $purchasersHtml = "<div>&bull; {$buyerName} (" . htmlspecialchars($offer->buyer_email ?? '') . ")</div>";
        }

        // Contingencies list
        $contingencies = $offer->contingencies ?? [];
        $contingenciesList = [];
        if (!empty($contingencies['financing'])) $contingenciesList[] = 'Financing Approval Contingency';
        if (!empty($contingencies['appraisal'])) $contingenciesList[] = 'Appraisal Value Contingency';
        if (!empty($contingencies['inspection']) || !empty($contingencies['property_inspection'])) $contingenciesList[] = 'Property Physical & Structural Inspection Contingency';
        if (!empty($contingencies['sale_of_home']) || !empty($contingencies['sale_buyers_home'])) $contingenciesList[] = 'Contingent on Sale of Buyer\'s Home';
        if (!empty($contingencies['lead_paint'])) $contingenciesList[] = 'Lead-Based Paint Risk Assessment';

        $contingenciesHtml = '';
        if (!empty($contingenciesList)) {
            foreach ($contingenciesList as $c) {
                $contingenciesHtml .= "<div>&#10003; " . htmlspecialchars($c) . "</div>";
            }
        } else {
            $contingenciesHtml = "<div>Standard statutory inspection and clear title conditions.</div>";
        }

        $additionalTerms = htmlspecialchars($offer->additional_terms ?? 'None specified.');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Purchase Agreement - {$offerCode}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.5; color: #1e293b; background: #fff; padding: 24px; margin: 0; }
        .contract-container { max-width: 820px; margin: 0 auto; border: 1px solid #cbd5e1; border-radius: 8px; padding: 36px; }
        .header-table { width: 100%; border-bottom: 2px solid #0f766e; padding-bottom: 12px; margin-bottom: 20px; border-collapse: collapse; }
        .header-table td { vertical-align: bottom; }
        .title { font-size: 20px; font-weight: bold; color: #0f766e; text-transform: uppercase; margin: 0; }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; background: #f8fafc; border: 1px solid #e2e8f0; }
        .meta-table td { padding: 8px 12px; font-size: 12px; border-bottom: 1px solid #e2e8f0; }
        .meta-table td strong { color: #334155; }
        h2 { font-size: 13px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; margin-top: 18px; margin-bottom: 10px; color: #0f172a; text-transform: uppercase; }
        .terms-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .terms-table th, .terms-table td { border: 1px solid #cbd5e1; padding: 7px 10px; font-size: 11px; text-align: left; }
        .terms-table th { background: #f1f5f9; font-weight: 600; color: #334155; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .badge-accepted { background: #ecfdf5; color: #047857; border: 1px solid #10b981; }
        .signatures-table { width: 100%; border-collapse: collapse; margin-top: 24px; border: 1px solid #cbd5e1; }
        .signatures-table td { width: 50%; padding: 14px; vertical-align: top; font-size: 11px; border: 1px solid #cbd5e1; }
        .sign-line { border-bottom: 1px solid #94a3b8; height: 32px; margin-top: 12px; }
    </style>
</head>
<body>
    <div class="contract-container">
        <table class="header-table">
            <tr>
                <td>
                    <div class="title">Residential Real Estate Purchase Agreement</div>
                    <div style="font-size:11px;color:#64748b;margin-top:2px;">Reozom Offer Contract & Transaction Agreement</div>
                </td>
                <td style="text-align:right;">
                    <div style="font-family:monospace;font-weight:bold;color:#0f766e;font-size:14px;">{$offerCode}</div>
                    <div style="font-size:11px;color:#64748b;">Listing: {$listingCode}</div>
                </td>
            </tr>
        </table>

        <table class="meta-table">
            <tr>
                <td style="width:50%;"><strong>Property Street Address:</strong><br>{$address}</td>
                <td style="width:50%;"><strong>Contract Status:</strong><br><span class="badge badge-accepted">{$statusLabel}</span></td>
            </tr>
            <tr>
                <td><strong>Seller(s):</strong><br>{$sellerName}</td>
                <td><strong>Purchaser(s):</strong><br>{$purchasersHtml}</td>
            </tr>
        </table>

        <h2>1. Purchase Price & Payment Terms</h2>
        <table class="terms-table">
            <thead>
                <tr>
                    <th style="width:35%;">Term</th>
                    <th style="width:65%;">Agreed Specifications</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Agreed Purchase Price</strong></td>
                    <td><strong style="font-size:13px;color:#0f766e;">\${$price} USD</strong></td>
                </tr>
                <tr>
                    <td><strong>Earnest Money Deposit</strong></td>
                    <td>\${$deposit} USD to be held in escrow by closing agent.</td>
                </tr>
                <tr>
                    <td><strong>Payment Method / Financing</strong></td>
                    <td>{$paymentMethod}</td>
                </tr>
                <tr>
                    <td><strong>Seller Concessions (Closing Credit)</strong></td>
                    <td>\${$concessions} USD</td>
                </tr>
                <tr>
                    <td><strong>Anticipated Closing Date</strong></td>
                    <td><strong>{$closingDate}</strong></td>
                </tr>
                <tr>
                    <td><strong>Possession & Occupancy</strong></td>
                    <td>{$occupancy}</td>
                </tr>
            </tbody>
        </table>

        <h2>2. Contract Contingencies & Conditions</h2>
        <div style="font-size:11px;color:#334155;line-height:1.6;margin-bottom:12px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;">
            {$contingenciesHtml}
        </div>

        <h2>3. Additional Terms & Stipulations</h2>
        <div style="font-size:11px;color:#475569;line-height:1.5;margin-bottom:16px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;">
            {$additionalTerms}
        </div>

        <h2>4. Offer Acceptance & Signatures</h2>
        <div style="font-size:10px;color:#64748b;">Offer date: {$date} &middot; Status: {$statusLabel}</div>
        {$signatureBlock}

        <div style="margin-top:16px;font-size:9px;color:#94a3b8;line-height:1.4;text-align:center;">
            This electronic contract document is generated through Reozom Real Estate Platform in accordance with the Uniform Electronic Transactions Act (UETA) and Federal E-SIGN Act.
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Admin view of all offers with search, filters, and stats.
     * Replicates Reozom Administration OffersController@index & OffersRepository@getAllOffers
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = ListingOffer::with([
            'listing:id,reference_code,address,city,state,zip,status,user_id',
            'listing.user:id,name,email,first_name,last_name',
            'buyer:id,name,email,first_name,last_name,role,is_active',
            'documents',
            'purchasers',
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

        // Include Reozom summary metrics
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
     * Replicates Reozom Administration OffersController@view
     */
    public function adminShow(Request $request, ListingOffer $offer): JsonResponse
    {
        return response()->json($offer->load([
            'listing:id,reference_code,address,city,state,zip,status,user_id',
            'listing.user:id,name,first_name,last_name,email,role,is_active',
            'buyer:id,name,email,first_name,last_name,role,is_active',
            'actions.user:id,name,first_name,last_name',
            'documents',
            'purchasers',
        ]));
    }
}
