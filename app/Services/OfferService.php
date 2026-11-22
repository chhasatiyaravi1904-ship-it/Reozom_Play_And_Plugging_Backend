<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ListingOffer;
use App\Models\ListingOfferAction;
use App\Models\ListingOfferDocument;
use App\Models\ListingOfferPurchaser;
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
     * Submit a new purchase offer on a listing (Buyer or Buyer's Agent).
     * Replicates Reozom: OffersRepository@createOffer & OffersController@postSendOffer
     *
     * @throws ValidationException
     */
    public function submitOffer(Listing $listing, User $buyer, array $data, array $documents = []): ListingOffer
    {
        // Listing must be published or under_contract/ccs (for backup offers)
        $normalizedListingStatus = ListingStatusService::normalizeStatus($listing->status);
        if (!in_array($normalizedListingStatus, ['published', 'under_contract', 'ccs'], true)) {
            throw ValidationException::withMessages([
                'listing' => ['Offers can only be submitted on published or under-contract listings.'],
            ]);
        }

        // Sellers cannot submit offers on their own listing
        if ($listing->user_id === $buyer->id) {
            throw ValidationException::withMessages([
                'buyer' => ['You are the owner of this listing. You cannot submit an offer to yourself.'],
            ]);
        }

        $offer = DB::transaction(function () use ($listing, $buyer, $data, $documents, $normalizedListingStatus) {
            $isBackup = in_array($normalizedListingStatus, ['under_contract', 'ccs'], true);

            // Parse deadline: supports integer hours (e.g. 24, 48) or datetime string
            $deadline = now()->addDays(3);
            if (!empty($data['deadline'])) {
                if (is_numeric($data['deadline'])) {
                    $deadline = now()->addHours((int) $data['deadline']);
                } else {
                    $deadline = Carbon::parse($data['deadline']);
                }
            }

            // Buyer / Purchaser representation
            $buyerRole = ($buyer->role instanceof \App\Enums\UserRole) ? $buyer->role->value : (string) $buyer->role;
            $hasAgent = !empty($data['has_agent_representation']) || !empty($data['agent_name']) || $buyerRole === 'agent';
            $buyerFullName = $data['buyer_name'] ?? ($buyer->first_name ? trim("{$buyer->first_name} {$buyer->last_name}") : $buyer->name);

            // Construct home address if contingent on buyer home sale
            $buyersHomeAddressUnderContract = $data['buyers_home_address_under_contract'] ?? null;
            if (empty($buyersHomeAddressUnderContract) && !empty($data['buyers_home_address'])) {
                $addrParts = [trim($data['buyers_home_address'])];
                if (!empty($data['buyers_home_unit_number'])) {
                    $addrParts[] = '#' . trim($data['buyers_home_unit_number']);
                }
                if (!empty($data['buyers_home_city'])) {
                    $addrParts[] = trim($data['buyers_home_city']);
                }
                if (!empty($data['buyers_home_state'])) {
                    $addrParts[] = trim($data['buyers_home_state']);
                }
                if (!empty($data['buyers_home_zip'])) {
                    $addrParts[] = trim($data['buyers_home_zip']);
                }
                $buyersHomeAddressUnderContract = implode(', ', $addrParts);
            }

            // Parse contingencies from either structured array or flat Reozom field names
            $contingencies = $data['contingencies'] ?? [];
            if (!is_array($contingencies)) {
                $contingencies = [];
            }
            if (isset($data['oc_property_inspection'])) {
                $contingencies['property_inspection'] = (int) $data['oc_property_inspection'] === 1;
                $contingencies['property_inspection_days'] = $data['oc_property_inspection_days'] ?? 10;
            }
            if (isset($data['oc_property_paint_inspection'])) {
                $contingencies['lead_paint_inspection'] = (int) $data['oc_property_paint_inspection'] === 1;
                $contingencies['lead_paint_days'] = $data['oc_property_paint_inspection_days'] ?? 10;
            }
            if (isset($data['oc_sale_buyers_home'])) {
                $contingencies['sale_of_home'] = (int) $data['oc_sale_buyers_home'] === 1;
                $contingencies['any_offer_accepted'] = (int) ($data['any_offer_accepted'] ?? 0) === 1;
                $contingencies['anticipated_close_date'] = $data['anticipated_close_date'] ?? null;
                $contingencies['buyers_home_under_contract'] = $data['buyers_home_under_contract'] ?? null;
            }
            if (isset($data['oc_well_inspection'])) {
                $contingencies['well_inspection'] = (int) $data['oc_well_inspection'] === 1;
                $contingencies['well_payment'] = $data['oc_well_payment'] ?? 'Buyer';
                $contingencies['well_days'] = $data['oc_well_days'] ?? 10;
            }
            if (isset($data['oc_septic_inspection'])) {
                $contingencies['septic_inspection'] = (int) $data['oc_septic_inspection'] === 1;
                $contingencies['septic_payment'] = $data['oc_septic_payment'] ?? 'Buyer';
                $contingencies['septic_days'] = $data['oc_septic_days'] ?? 10;
            }
            if (isset($data['oc_survey'])) {
                $contingencies['survey'] = (int) $data['oc_survey'] === 1;
            }
            if (isset($data['oc_review_buyers_attorney'])) {
                $contingencies['attorney_review'] = (int) $data['oc_review_buyers_attorney'] === 1;
            }

            // Appliances
            $appliances = $data['appliances'] ?? [];
            if (is_string($appliances)) {
                $appliances = array_map('trim', explode(',', $appliances));
            }
            $otherAppliances = $data['other_appliances'] ?? null;
            if (is_array($otherAppliances)) {
                $otherAppliances = implode(', ', array_filter($otherAppliances));
            }

            $offer = ListingOffer::create([
                'listing_id' => $listing->id,
                'buyer_id' => $buyer->id,
                'status' => ListingOffer::STATUS_SUBMITTED,
                'offer_amount' => $data['offer_amount'],
                'direct_buyer_rebate' => $data['direct_buyer_rebate'] ?? null,
                'earnest_money_deposit' => $data['earnest_money_deposit'] ?? ($data['deposit_amount'] ?? 0),
                'down_payment_amount' => $data['down_payment_amount'] ?? null,
                'payment_method' => $data['payment_method'] ?? 'conventional',
                'mortgage_application_provided_by' => !empty($data['mortgage_application_provided_by']) ? Carbon::parse($data['mortgage_application_provided_by']) : null,
                'loan_approval_provided_by' => !empty($data['loan_approval_provided_by']) ? Carbon::parse($data['loan_approval_provided_by']) : null,
                'closing_date' => $data['closing_date'],
                'occupancy_type' => $data['occupancy_type'] ?? 'at_closing',
                'occupancy_days' => $data['occupancy_days'] ?? 0,
                'fee_per_day' => $data['fee_per_day'] ?? 0,
                'seller_concessions' => $data['seller_concessions'] ?? ($data['closing_amount'] ?? 0),
                'closing_amount_type' => $data['closing_amount_type'] ?? 2, // 1 - %, 2 - $
                'commission' => $data['commission'] ?? 0,
                'commission_type' => $data['commission_type'] ?? 1, // 1 - %, 2 - $
                'home_warranty_included' => !empty($data['home_warranty_included']),
                'home_warranty_price' => $data['home_warranty_price'] ?? null,
                'buyers_home_address_under_contract' => $buyersHomeAddressUnderContract,
                'contingencies' => $contingencies,
                'appliances' => $appliances,
                'other_appliances' => $otherAppliances,
                'deadline' => $deadline,
                'additional_terms' => $data['additional_terms'] ?? ($data['other_conditions'] ?? null),
                'is_backup_offer' => $isBackup,
                'buyer_name' => $buyerFullName,
                'buyer_email' => $data['buyer_email'] ?? $buyer->email,
                'buyer_phone' => $data['buyer_phone'] ?? $buyer->phone,
                'has_agent_representation' => $hasAgent,
                'agent_name' => $data['agent_name'] ?? null,
                'agent_email' => $data['agent_email'] ?? null,
                'agent_phone' => $data['agent_phone'] ?? null,
            ]);

            // Save main purchaser record
            ListingOfferPurchaser::create([
                'listing_offer_id' => $offer->id,
                'first_name' => $data['main_purchaser_first_name'] ?? ($buyer->first_name ?: $buyerFullName),
                'last_name' => $data['main_purchaser_last_name'] ?? ($buyer->last_name ?: ''),
                'email' => $data['main_purchaser_email'] ?? $buyer->email,
                'street_address' => $data['main_purchaser_address'] ?? null,
                'city' => $data['main_purchaser_city'] ?? null,
                'state_id' => $data['main_purchaser_state'] ?? null,
                'zip' => $data['main_purchaser_zip'] ?? null,
                'non_us_citizen' => !empty($data['main_purchaser_non_us_citizen']) ? 1 : 0,
                'is_main' => true,
            ]);

            // Save additional co-purchasers if provided
            if (!empty($data['purchasers']) && is_array($data['purchasers'])) {
                foreach ($data['purchasers'] as $p) {
                    if (!empty($p['first_name'])) {
                        ListingOfferPurchaser::create([
                            'listing_offer_id' => $offer->id,
                            'first_name' => $p['first_name'],
                            'last_name' => $p['last_name'] ?? '',
                            'email' => $p['email'] ?? '',
                            'street_address' => $p['street_address'] ?? ($p['address'] ?? null),
                            'city' => $p['city'] ?? null,
                            'state_id' => $p['state_id'] ?? ($p['state'] ?? null),
                            'zip' => $p['zip'] ?? null,
                            'non_us_citizen' => !empty($p['non_us_citizen']) ? 1 : 0,
                            'is_main' => false,
                        ]);
                    }
                }
            }

            // Record initial action in negotiation audit trail
            ListingOfferAction::create([
                'listing_offer_id' => $offer->id,
                'user_id' => $buyer->id,
                'actor_type' => 'buyer',
                'action_type' => 'submitted',
                'offer_amount' => $offer->offer_amount,
                'deadline' => $offer->deadline,
                'terms_snapshot' => $offer->toArray(),
                'comment' => $data['comment'] ?? ($data['message'] ?? 'Initial purchase agreement submitted.'),
            ]);

            // Attach supporting documents (Pre-Approval Letter / Proof of Funds)
            foreach ($documents as $docPayload) {
                if (isset($docPayload['file']) && $docPayload['file'] instanceof UploadedFile) {
                    $this->attachDocument($offer, $buyer, $docPayload['file'], $docPayload['type'] ?? 'pre_approval_letter');
                }
            }

            return $offer->load(['actions', 'documents', 'purchasers']);
        });

        $this->sendLeadPamphlet($offer, $listing, $buyer);

        return $offer;
    }

    /**
     * Reozom emails the EPA lead pamphlet to the buyer (or their agent) and every purchaser when an
     * offer is made on a home built before 1978. A mail failure never blocks the offer.
     */
    private function sendLeadPamphlet(ListingOffer $offer, Listing $listing, User $buyer): void
    {
        $yearBuilt = $listing->answerValue('year_built');
        if (! is_numeric($yearBuilt) || (int) $yearBuilt >= 1978 || ! is_file(resource_path('documents/lead-pamphlet.pdf'))) {
            return;
        }

        try {
            $notification = new \App\Notifications\LeadPamphletNotification($offer);
            $buyer->notify($notification);

            $sent = [strtolower((string) $buyer->email)];
            foreach ($offer->purchasers as $purchaser) {
                $email = strtolower(trim((string) $purchaser->email));
                if (filter_var($email, FILTER_VALIDATE_EMAIL) && ! in_array($email, $sent, true)) {
                    \Illuminate\Support\Facades\Notification::route('mail', $email)->notify($notification);
                    $sent[] = $email;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Lead pamphlet email failed.', ['offer_id' => $offer->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Submit a counter-offer with strict Reozom permissions and term modification validation.
     * Replicates Reozom: OffersRepository@createCounterOffer & OffersController@postSendCounteroffer
     *
     * @throws ValidationException
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

            $isSeller = ($lockedOffer->listing->user_id === $actor->id || $actor->isAdmin());
            $isBuyer = ($lockedOffer->buyer_id === $actor->id);

            if (!$isSeller && !$isBuyer) {
                throw ValidationException::withMessages([
                    'offer' => ['Unauthorized to counter this offer.'],
                ]);
            }

            // Reozom Permission rules:
            // Seller can counter when offer is submitted or countered_by_buyer
            if ($isSeller && $lockedOffer->status === ListingOffer::STATUS_COUNTERED_BY_SELLER) {
                throw ValidationException::withMessages([
                    'offer' => ['You have already submitted an active counter-offer. Waiting for buyer response.'],
                ]);
            }

            // Buyer can counter when offer was countered_by_seller
            if ($isBuyer && !$isSeller && $lockedOffer->status !== ListingOffer::STATUS_COUNTERED_BY_SELLER) {
                throw ValidationException::withMessages([
                    'offer' => ['You can only counter when the seller has sent a counter-proposal.'],
                ]);
            }

            $newStatus = $isSeller ? ListingOffer::STATUS_COUNTERED_BY_SELLER : ListingOffer::STATUS_COUNTERED_BY_BUYER;
            $actorType = $isSeller ? 'seller' : 'buyer';

            // Parse revised deadline (hours or date)
            $deadline = now()->addDays(2);
            if (!empty($data['deadline'])) {
                if (is_numeric($data['deadline'])) {
                    $deadline = now()->addHours((int) $data['deadline']);
                } else {
                    $deadline = Carbon::parse($data['deadline']);
                }
            }

            // Verify terms modification
            $proposedAmount = isset($data['offer_amount']) ? (float) $data['offer_amount'] : (float) $lockedOffer->offer_amount;
            $proposedDeposit = isset($data['earnest_money_deposit']) ? (float) $data['earnest_money_deposit'] : (float) $lockedOffer->earnest_money_deposit;
            $proposedConcessions = isset($data['seller_concessions']) ? (float) $data['seller_concessions'] : (float) $lockedOffer->seller_concessions;

            $hasPriceChange = abs($proposedAmount - (float) $lockedOffer->offer_amount) > 0.01;
            $hasDepositChange = abs($proposedDeposit - (float) $lockedOffer->earnest_money_deposit) > 0.01;
            $hasConcessionChange = abs($proposedConcessions - (float) $lockedOffer->seller_concessions) > 0.01;
            $hasClosingDateChange = !empty($data['closing_date']) && $data['closing_date'] !== $lockedOffer->closing_date?->format('Y-m-d');
            $hasTermsChange = !empty($data['additional_terms']) && $data['additional_terms'] !== $lockedOffer->additional_terms;

            if (!$hasPriceChange && !$hasDepositChange && !$hasConcessionChange && !$hasClosingDateChange && !$hasTermsChange && empty($data['comment'])) {
                throw ValidationException::withMessages([
                    'offer' => ['No terms of the offer have been modified. Please modify terms or add negotiation terms to counter.'],
                ]);
            }

            // Build updated terms
            $updatedTerms = [
                'status' => $newStatus,
                'offer_amount' => $proposedAmount,
                'earnest_money_deposit' => $proposedDeposit,
                'seller_concessions' => $proposedConcessions,
                'deadline' => $deadline,
            ];

            if (isset($data['down_payment_amount'])) {
                $updatedTerms['down_payment_amount'] = $data['down_payment_amount'];
            }
            if (isset($data['closing_amount_type'])) {
                $updatedTerms['closing_amount_type'] = $data['closing_amount_type'];
            }
            if (!empty($data['closing_date'])) {
                $updatedTerms['closing_date'] = $data['closing_date'];
            }
            if (!empty($data['occupancy_type'])) {
                $updatedTerms['occupancy_type'] = $data['occupancy_type'];
            }
            if (isset($data['occupancy_days'])) {
                $updatedTerms['occupancy_days'] = $data['occupancy_days'];
            }
            if (isset($data['fee_per_day'])) {
                $updatedTerms['fee_per_day'] = $data['fee_per_day'];
            }
            if (isset($data['commission'])) {
                $updatedTerms['commission'] = $data['commission'];
            }
            if (isset($data['commission_type'])) {
                $updatedTerms['commission_type'] = $data['commission_type'];
            }
            if (isset($data['home_warranty_included'])) {
                $updatedTerms['home_warranty_included'] = (bool) $data['home_warranty_included'];
            }
            if (isset($data['contingencies'])) {
                $updatedTerms['contingencies'] = $data['contingencies'];
            }
            if (isset($data['additional_terms'])) {
                $updatedTerms['additional_terms'] = $data['additional_terms'];
            }

            $lockedOffer->update($updatedTerms);

            // Record action in negotiation trail
            ListingOfferAction::create([
                'listing_offer_id' => $lockedOffer->id,
                'user_id' => $actor->id,
                'actor_type' => $actorType,
                'action_type' => 'counter',
                'offer_amount' => $lockedOffer->offer_amount,
                'deadline' => $lockedOffer->deadline,
                'terms_snapshot' => $lockedOffer->toArray(),
                'comment' => $data['comment'] ?? ($data['message'] ?? "Counter-offer proposed by {$actorType}."),
            ]);

            return $lockedOffer->load(['actions', 'documents', 'purchasers']);
        });
    }

    /**
     * Accept an offer or counter-offer with concurrency safety.
     * Replicates Reozom: OffersRepository@acceptOffer & acceptCounterOffer
     * Atomically transitions the listing to `under_contract` (Pending) or `ccs` (Contingent - Continue to Show).
     *
     * @throws ValidationException
     */
    public function acceptOffer(ListingOffer $offer, User $actor, ?string $comment = null): ListingOffer
    {
        $accepted = DB::transaction(function () use ($offer, $actor, $comment) {
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

            $isSeller = ($lockedListing->user_id === $actor->id || $actor->isAdmin());
            $isBuyer = ($lockedOffer->buyer_id === $actor->id);

            if (!$isSeller && !$isBuyer) {
                throw ValidationException::withMessages([
                    'offer' => ['Unauthorized to accept this offer.'],
                ]);
            }

            // Validation of roles and transitions
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

            // Mark offer accepted
            $lockedOffer->update(['status' => ListingOffer::STATUS_ACCEPTED]);

            $actorType = $isSeller ? 'seller' : 'buyer';

            // Record action in history
            ListingOfferAction::create([
                'listing_offer_id' => $lockedOffer->id,
                'user_id' => $actor->id,
                'actor_type' => $actorType,
                'action_type' => 'accepted',
                'offer_amount' => $lockedOffer->offer_amount,
                'terms_snapshot' => $lockedOffer->toArray(),
                'comment' => $comment ?? 'Offer accepted and purchase contract executed.',
            ]);

            // Determine listing status: Reozom CCS vs. Standard Pending
            $contingencies = $lockedOffer->contingencies ?? [];
            $isContingentOnHomeSale = !empty($contingencies['sale_of_home']) ||
                                      !empty($contingencies['sale_buyers_home']) ||
                                      !empty($contingencies['oc_sale_buyers_home']);

            $newListingStatus = $isContingentOnHomeSale ? 'ccs' : 'under_contract';

            // Closing / Sold Metadata
            $soldMetadata = [
                'accepted_offer_id' => $lockedOffer->id,
                'reference_code' => $lockedOffer->reference_code,
                'anticipated_closed_date' => $lockedOffer->closing_date?->format('Y-m-d'),
                'sales_price' => (float) $lockedOffer->offer_amount,
                'sellers_concessions' => (float) $lockedOffer->seller_concessions > 0 ? 1 : 2,
                'amount' => (float) $lockedOffer->seller_concessions,
                'finance_type' => $lockedOffer->payment_method,
                'agent' => $lockedOffer->has_agent_representation ? 1 : 2,
                'agent_name' => $lockedOffer->agent_name,
            ];

            // Transition listing status
            $this->listingStatusService->transition(
                $lockedListing,
                $newListingStatus,
                $actor,
                $actorType,
                "Listing moved to {$newListingStatus} upon accepting offer {$lockedOffer->reference_code} for \${$lockedOffer->offer_amount}.",
                $soldMetadata
            );

            // Replicate Reozom competing offers revocation:
            // All other active pending offers on this listing are marked rejected_under_contract
            ListingOffer::where('listing_id', $lockedListing->id)
                ->where('id', '!=', $lockedOffer->id)
                ->whereIn('status', [
                    ListingOffer::STATUS_SUBMITTED,
                    ListingOffer::STATUS_COUNTERED_BY_SELLER,
                    ListingOffer::STATUS_COUNTERED_BY_BUYER,
                ])
                ->update(['status' => ListingOffer::STATUS_REJECTED_UNDER_CONTRACT]);

            return $lockedOffer->load(['actions', 'documents', 'purchasers']);
        });

        // Reozom sends a new purchase agreement on every acceptance: close any open one so the next
        // Sign creates it with the accepted terms (outside the transaction: EunoDoc is an HTTP call)
        $this->cancelOpenPurchaseAgreements($accepted);

        return $accepted;
    }

    /**
     * Cancel the offer's open purchase agreement(s) on EunoDoc and locally. A failed EunoDoc
     * cancel is logged; the local agreement is still closed so a new one can be sent.
     */
    public function cancelOpenPurchaseAgreements(ListingOffer $offer): void
    {
        $euno = app(\App\Services\EunoService::class);

        \App\Models\ListingEunoAgreement::where('listing_offer_id', $offer->id)
            ->where('document_type', 'purchase_agreement')
            ->where('status', 'inprogress')
            ->get()
            ->each(function (\App\Models\ListingEunoAgreement $agreement) use ($euno) {
                if ($agreement->agreement_id && $euno->cancelAgreement($agreement->agreement_id) === null) {
                    \Illuminate\Support\Facades\Log::warning('EunoDoc cancel failed; closing the agreement locally.', [
                        'listing_euno_agreement_id' => $agreement->id,
                    ]);
                }
                $agreement->update(['status' => 'canceled']);
            });
    }

    /**
     * Decline / reject an offer or counter-offer.
     * Replicates Reozom: OffersRepository@declineOffer & declineCounterOffer
     *
     * @throws ValidationException
     */
    public function rejectOffer(ListingOffer $offer, User $actor, ?string $reason = null): ListingOffer
    {
        return DB::transaction(function () use ($offer, $actor, $reason) {
            /** @var ListingOffer $lockedOffer */
            $lockedOffer = ListingOffer::where('id', $offer->id)->lockForUpdate()->firstOrFail();

            if (!$lockedOffer->isPendingResponse()) {
                throw ValidationException::withMessages([
                    'offer' => ['Cannot reject an inactive or already finalized offer.'],
                ]);
            }

            $isSeller = ($lockedOffer->listing->user_id === $actor->id || $actor->isAdmin());
            $isBuyer = ($lockedOffer->buyer_id === $actor->id);

            if (!$isSeller && !$isBuyer) {
                throw ValidationException::withMessages([
                    'offer' => ['Unauthorized to reject this offer.'],
                ]);
            }

            // Buyer can only decline seller counter
            if ($isBuyer && !$isSeller && $lockedOffer->status !== ListingOffer::STATUS_COUNTERED_BY_SELLER) {
                throw ValidationException::withMessages([
                    'offer' => ['Buyers can only decline an active counter-offer received from the seller.'],
                ]);
            }

            // Seller can decline submitted or countered_by_buyer
            if ($isSeller && $lockedOffer->status === ListingOffer::STATUS_COUNTERED_BY_SELLER) {
                throw ValidationException::withMessages([
                    'offer' => ['You cannot decline your own counter-offer. You may withdraw it instead.'],
                ]);
            }

            $lockedOffer->update(['status' => ListingOffer::STATUS_REJECTED]);

            $actorType = $isSeller ? 'seller' : 'buyer';

            ListingOfferAction::create([
                'listing_offer_id' => $lockedOffer->id,
                'user_id' => $actor->id,
                'actor_type' => $actorType,
                'action_type' => 'rejected',
                'offer_amount' => $lockedOffer->offer_amount,
                'terms_snapshot' => $lockedOffer->toArray(),
                'comment' => $reason ?? 'Offer declined.',
            ]);

            return $lockedOffer->load(['actions', 'documents', 'purchasers']);
        });
    }

    /**
     * Cancel or withdraw an offer.
     * Replicates Reozom: OffersRepository@cancelOffer & cancelCounteroffer
     * Buyer can withdraw their own offer/counter; Seller can cancel their active counter.
     *
     * @throws ValidationException
     */
    public function withdrawOffer(ListingOffer $offer, User $actor, ?string $reason = null): ListingOffer
    {
        return DB::transaction(function () use ($offer, $actor, $reason) {
            /** @var ListingOffer $lockedOffer */
            $lockedOffer = ListingOffer::where('id', $offer->id)->lockForUpdate()->firstOrFail();

            if (!$lockedOffer->isPendingResponse()) {
                throw ValidationException::withMessages([
                    'offer' => ['Cannot withdraw an offer that is already finalized.'],
                ]);
            }

            $isSeller = ($lockedOffer->listing->user_id === $actor->id || $actor->isAdmin());
            $isBuyer = ($lockedOffer->buyer_id === $actor->id);

            if (!$isSeller && !$isBuyer) {
                throw ValidationException::withMessages([
                    'offer' => ['Unauthorized to withdraw this offer.'],
                ]);
            }

            // Seller can only cancel their own counter-offer
            if ($isSeller && !$isBuyer && $lockedOffer->status !== ListingOffer::STATUS_COUNTERED_BY_SELLER) {
                throw ValidationException::withMessages([
                    'offer' => ['Sellers can only cancel an active counter-offer that they sent.'],
                ]);
            }

            // Buyer can only withdraw submitted or countered_by_buyer
            if ($isBuyer && !$isSeller && $lockedOffer->status === ListingOffer::STATUS_COUNTERED_BY_SELLER) {
                throw ValidationException::withMessages([
                    'offer' => ['You cannot withdraw the seller\'s counter-offer. You may decline it instead.'],
                ]);
            }

            $actorType = $isSeller ? 'seller' : 'buyer';
            $lockedOffer->update(['status' => ListingOffer::STATUS_WITHDRAWN]);

            ListingOfferAction::create([
                'listing_offer_id' => $lockedOffer->id,
                'user_id' => $actor->id,
                'actor_type' => $actorType,
                'action_type' => 'withdrawn',
                'offer_amount' => $lockedOffer->offer_amount,
                'terms_snapshot' => $lockedOffer->toArray(),
                'comment' => $reason ?? "Offer withdrawn by {$actorType}.",
            ]);

            return $lockedOffer->load(['actions', 'documents', 'purchasers']);
        });
    }

    /**
     * Attach a legal document or pre-approval letter to an offer.
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
