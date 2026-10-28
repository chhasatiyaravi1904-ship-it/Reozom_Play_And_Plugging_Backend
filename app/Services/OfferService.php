<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ListingOffer;
use App\Models\ListingOfferAction;
use App\Models\ListingOfferDocument;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OfferService
{
    public function __construct(
        protected ListingStatusService $listingStatusService
    ) {}

    /**
     * Submit a new offer on a listing by a buyer.
     */
    public function submitOffer(Listing $listing, User $buyer, array $data, array $documents = []): ListingOffer
    {
        // Listing must be published or under_contract (for backup offers)
        if (!in_array($listing->status, ['published', 'under_contract'], true)) {
            throw ValidationException::withMessages([
                'listing' => ['Offers can only be submitted on published listings.'],
            ]);
        }

        // Sellers cannot submit offers on their own listing
        if ($listing->user_id === $buyer->id) {
            throw ValidationException::withMessages([
                'buyer' => ['You cannot submit an offer on your own listing.'],
            ]);
        }

        return DB::transaction(function () use ($listing, $buyer, $data, $documents) {
            $isBackup = ($listing->status === 'under_contract');

            $offer = ListingOffer::create([
                'listing_id' => $listing->id,
                'buyer_id' => $buyer->id,
                'status' => ListingOffer::STATUS_SUBMITTED,
                'offer_amount' => $data['offer_amount'],
                'earnest_money_deposit' => $data['earnest_money_deposit'] ?? 0,
                'down_payment_amount' => $data['down_payment_amount'] ?? null,
                'payment_method' => $data['payment_method'] ?? 'conventional',
                'closing_date' => $data['closing_date'],
                'occupancy_type' => $data['occupancy_type'] ?? 'at_closing',
                'occupancy_days' => $data['occupancy_days'] ?? 0,
                'seller_concessions' => $data['seller_concessions'] ?? 0,
                'contingencies' => $data['contingencies'] ?? [],
                'deadline' => isset($data['deadline']) ? Carbon::parse($data['deadline']) : now()->addDays(3),
                'additional_terms' => $data['additional_terms'] ?? null,
                'is_backup_offer' => $isBackup,
                'buyer_name' => $data['buyer_name'] ?? ($buyer->first_name ? ($buyer->first_name . ' ' . $buyer->last_name) : $buyer->name),
                'buyer_email' => $data['buyer_email'] ?? $buyer->email,
                'buyer_phone' => $data['buyer_phone'] ?? $buyer->phone,
                'has_agent_representation' => (bool) ($data['has_agent_representation'] ?? false),
                'agent_name' => $data['agent_name'] ?? null,
                'agent_email' => $data['agent_email'] ?? null,
                'agent_phone' => $data['agent_phone'] ?? null,
            ]);

            // Record initial action
            ListingOfferAction::create([
                'listing_offer_id' => $offer->id,
                'user_id' => $buyer->id,
                'actor_type' => 'buyer',
                'action_type' => 'submitted',
                'offer_amount' => $offer->offer_amount,
                'deadline' => $offer->deadline,
                'terms_snapshot' => $offer->toArray(),
                'comment' => $data['comment'] ?? 'Initial offer submitted.',
            ]);

            // Attach supporting documents if provided
            foreach ($documents as $docPayload) {
                if (isset($docPayload['file']) && $docPayload['file'] instanceof UploadedFile) {
                    $this->attachDocument($offer, $buyer, $docPayload['file'], $docPayload['type'] ?? 'pre_approval_letter');
                }
            }

            return $offer->load(['actions', 'documents']);
        });
    }

    /**
     * Submit a counter-offer.
     */
    public function counterOffer(ListingOffer $offer, User $actor, array $data): ListingOffer
    {
        return DB::transaction(function () use ($offer, $actor, $data) {
            /** @var ListingOffer $lockedOffer */
            $lockedOffer = ListingOffer::where('id', $offer->id)->lockForUpdate()->firstOrFail();

            if (!$lockedOffer->isPendingResponse()) {
                throw ValidationException::withMessages([
                    'offer' => ['Cannot counter an offer that is not currently active.'],
                ]);
            }

            if ($lockedOffer->isExpired()) {
                $lockedOffer->update(['status' => ListingOffer::STATUS_EXPIRED]);
                throw ValidationException::withMessages([
                    'offer' => ['Cannot counter an expired offer.'],
                ]);
            }

            $isSeller = ($lockedOffer->listing->user_id === $actor->id || $actor->hasRole('admin'));
            $newStatus = $isSeller ? ListingOffer::STATUS_COUNTERED_BY_SELLER : ListingOffer::STATUS_COUNTERED_BY_BUYER;
            $actorType = $isSeller ? 'seller' : 'buyer';

            // Update offer terms
            $updatedTerms = [
                'status' => $newStatus,
                'offer_amount' => $data['offer_amount'] ?? $lockedOffer->offer_amount,
                'earnest_money_deposit' => $data['earnest_money_deposit'] ?? $lockedOffer->earnest_money_deposit,
                'closing_date' => $data['closing_date'] ?? $lockedOffer->closing_date,
                'seller_concessions' => $data['seller_concessions'] ?? $lockedOffer->seller_concessions,
                'deadline' => isset($data['deadline']) ? Carbon::parse($data['deadline']) : now()->addDays(2),
            ];

            if (isset($data['additional_terms'])) {
                $updatedTerms['additional_terms'] = $data['additional_terms'];
            }

            $lockedOffer->update($updatedTerms);

            // Record action in negotiation chain
            ListingOfferAction::create([
                'listing_offer_id' => $lockedOffer->id,
                'user_id' => $actor->id,
                'actor_type' => $actorType,
                'action_type' => 'counter',
                'offer_amount' => $lockedOffer->offer_amount,
                'deadline' => $lockedOffer->deadline,
                'terms_snapshot' => $lockedOffer->toArray(),
                'comment' => $data['comment'] ?? "Counter-offer sent by {$actorType}.",
            ]);

            return $lockedOffer->load(['actions', 'documents']);
        });
    }

    /**
     * Accept an offer or counter-offer with concurrency safety.
     * Acceptance atomically transitions the listing to `under_contract` and handles competing offers.
     */
    public function acceptOffer(ListingOffer $offer, User $actor, ?string $comment = null): ListingOffer
    {
        return DB::transaction(function () use ($offer, $actor, $comment) {
            /** @var ListingOffer $lockedOffer */
            $lockedOffer = ListingOffer::where('id', $offer->id)->lockForUpdate()->firstOrFail();
            /** @var Listing $lockedListing */
            $lockedListing = Listing::where('id', $lockedOffer->listing_id)->lockForUpdate()->firstOrFail();

            if (!$lockedOffer->isPendingResponse()) {
                throw ValidationException::withMessages([
                    'offer' => ['Only active offers can be accepted.'],
                ]);
            }

            if ($lockedOffer->isExpired()) {
                $lockedOffer->update(['status' => ListingOffer::STATUS_EXPIRED]);
                throw ValidationException::withMessages([
                    'offer' => ['Cannot accept an expired offer.'],
                ]);
            }

            $isSeller = ($lockedListing->user_id === $actor->id || $actor->hasRole('admin'));

            // Validation: Seller can accept initial offer or buyer counter; Buyer can accept seller counter
            if ($isSeller && $lockedOffer->status === ListingOffer::STATUS_COUNTERED_BY_SELLER) {
                throw ValidationException::withMessages([
                    'offer' => ['You cannot accept your own counter-offer. Waiting for buyer response.'],
                ]);
            }

            if (!$isSeller && $lockedOffer->status !== ListingOffer::STATUS_COUNTERED_BY_SELLER) {
                throw ValidationException::withMessages([
                    'offer' => ['Buyers can only accept an active counter-offer from the seller.'],
                ]);
            }

            // Accept offer
            $lockedOffer->update(['status' => ListingOffer::STATUS_ACCEPTED]);

            // Record action
            ListingOfferAction::create([
                'listing_offer_id' => $lockedOffer->id,
                'user_id' => $actor->id,
                'actor_type' => $isSeller ? 'seller' : 'buyer',
                'action_type' => 'accepted',
                'offer_amount' => $lockedOffer->offer_amount,
                'terms_snapshot' => $lockedOffer->toArray(),
                'comment' => $comment ?? 'Offer accepted and under contract.',
            ]);

            // Transition listing status to under_contract
            $this->listingStatusService->transition(
                $lockedListing,
                'under_contract',
                $actor,
                $isSeller ? 'seller' : 'buyer',
                "Listing moved to under_contract upon accepting offer {$lockedOffer->reference_code} for \${$lockedOffer->offer_amount}.",
                ['accepted_offer_id' => $lockedOffer->id, 'reference_code' => $lockedOffer->reference_code]
            );

            // Handle other pending offers on this listing
            ListingOffer::where('listing_id', $lockedListing->id)
                ->where('id', '!=', $lockedOffer->id)
                ->whereIn('status', [
                    ListingOffer::STATUS_SUBMITTED,
                    ListingOffer::STATUS_COUNTERED_BY_SELLER,
                    ListingOffer::STATUS_COUNTERED_BY_BUYER,
                ])
                ->update(['status' => ListingOffer::STATUS_REJECTED_UNDER_CONTRACT]);

            return $lockedOffer->load(['actions', 'documents']);
        });
    }

    /**
     * Reject an offer.
     */
    public function rejectOffer(ListingOffer $offer, User $actor, ?string $reason = null): ListingOffer
    {
        return DB::transaction(function () use ($offer, $actor, $reason) {
            $lockedOffer = ListingOffer::where('id', $offer->id)->lockForUpdate()->firstOrFail();

            if (!$lockedOffer->isPendingResponse()) {
                throw ValidationException::withMessages([
                    'offer' => ['Cannot reject an inactive offer.'],
                ]);
            }

            $lockedOffer->update(['status' => ListingOffer::STATUS_REJECTED]);

            ListingOfferAction::create([
                'listing_offer_id' => $lockedOffer->id,
                'user_id' => $actor->id,
                'actor_type' => ($lockedOffer->listing->user_id === $actor->id) ? 'seller' : 'buyer',
                'action_type' => 'rejected',
                'offer_amount' => $lockedOffer->offer_amount,
                'terms_snapshot' => $lockedOffer->toArray(),
                'comment' => $reason ?? 'Offer rejected.',
            ]);

            return $lockedOffer->load(['actions', 'documents']);
        });
    }

    /**
     * Withdraw an offer (Buyer only).
     */
    public function withdrawOffer(ListingOffer $offer, User $buyer, ?string $reason = null): ListingOffer
    {
        return DB::transaction(function () use ($offer, $buyer, $reason) {
            $lockedOffer = ListingOffer::where('id', $offer->id)->lockForUpdate()->firstOrFail();

            if ($lockedOffer->buyer_id !== $buyer->id && !$buyer->hasRole('admin')) {
                throw ValidationException::withMessages([
                    'offer' => ['Only the buyer who submitted the offer may withdraw it.'],
                ]);
            }

            if (!$lockedOffer->isPendingResponse()) {
                throw ValidationException::withMessages([
                    'offer' => ['Cannot withdraw an offer that is already finalized.'],
                ]);
            }

            $lockedOffer->update(['status' => ListingOffer::STATUS_WITHDRAWN]);

            ListingOfferAction::create([
                'listing_offer_id' => $lockedOffer->id,
                'user_id' => $buyer->id,
                'actor_type' => 'buyer',
                'action_type' => 'withdrawn',
                'offer_amount' => $lockedOffer->offer_amount,
                'terms_snapshot' => $lockedOffer->toArray(),
                'comment' => $reason ?? 'Offer withdrawn by buyer.',
            ]);

            return $lockedOffer->load(['actions', 'documents']);
        });
    }

    /**
     * Attach a document to an offer securely.
     */
    public function attachDocument(ListingOffer $offer, User $user, UploadedFile $file, string $type): ListingOfferDocument
    {
        $path = $file->store("offers/{$offer->id}/documents", 'local');

        return ListingOfferDocument::create([
            'listing_offer_id' => $offer->id,
            'user_id' => $user->id,
            'type' => $type,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
        ]);
    }
}
