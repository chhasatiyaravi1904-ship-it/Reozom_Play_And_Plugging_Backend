<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ListingSoldDetails;
use App\Models\ListingStatusHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ListingStatusService
{
    /**
     * Map Reozom numeric status codes to standard string identifiers.
     */
    protected static array $numericToString = [
        Listing::STATUS_NOT_APPROVED => 'rejected',
        Listing::STATUS_ACTIVE_WAITING_FOR_APPROVAL => 'submitted',
        Listing::STATUS_ACTIVE => 'published',
        Listing::STATUS_PENDING => 'under_contract',
        Listing::STATUS_CCS => 'ccs',
        Listing::STATUS_SOLD => 'sold',
        Listing::STATUS_CANCELED => 'canceled',
        Listing::STATUS_SUSPENDED => 'suspended',
        Listing::STATUS_EXPIRED => 'expired',
    ];

    /**
     * Map string identifiers back to Reozom numeric codes.
     */
    protected static array $stringToNumeric = [
        'rejected' => Listing::STATUS_NOT_APPROVED,
        'draft' => Listing::STATUS_ACTIVE_WAITING_FOR_APPROVAL,
        'in_progress' => Listing::STATUS_ACTIVE_WAITING_FOR_APPROVAL,
        'submitted' => Listing::STATUS_ACTIVE_WAITING_FOR_APPROVAL,
        'published' => Listing::STATUS_ACTIVE,
        'active' => Listing::STATUS_ACTIVE,
        'under_contract' => Listing::STATUS_PENDING,
        'pending' => Listing::STATUS_PENDING,
        'ccs' => Listing::STATUS_CCS,
        'contingent_continue_to_show' => Listing::STATUS_CCS,
        'sold' => Listing::STATUS_SOLD,
        'canceled' => Listing::STATUS_CANCELED,
        'suspended' => Listing::STATUS_SUSPENDED,
        'expired' => Listing::STATUS_EXPIRED,
        'withdrawn' => Listing::STATUS_CANCELED,
        'unpublished' => Listing::STATUS_ACTIVE_WAITING_FOR_APPROVAL,
    ];

    /**
     * Permitted state transitions matrix replicating Reozom workflow rules.
     * key: from_status => array of allowed to_status
     */
    protected static array $transitions = [
        'draft' => ['in_progress', 'canceled'],
        'in_progress' => ['submitted', 'draft', 'canceled'],
        'submitted' => ['published', 'in_progress', 'rejected', 'canceled'],
        'published' => ['under_contract', 'ccs', 'in_progress', 'suspended', 'withdrawn', 'canceled', 'expired', 'unpublished'],
        'under_contract' => ['sold', 'published', 'canceled', 'ccs'],
        'ccs' => ['under_contract', 'sold', 'published', 'canceled'],
        'suspended' => ['published', 'withdrawn', 'canceled', 'expired'],
        'rejected' => ['in_progress', 'submitted', 'canceled'],
        'withdrawn' => ['in_progress', 'canceled', 'published'],
        'expired' => ['in_progress', 'canceled', 'published'],
        'unpublished' => ['published', 'in_progress', 'canceled'],
        'sold' => ['published', 'under_contract'], // Reversible via admin override only
        'canceled' => ['in_progress', 'published'], // Can be reopened
    ];

    /**
     * Normalize status input (numeric or string) to canonical string identifier.
     */
    public static function normalizeStatus(string|int $status): string
    {
        if (is_numeric($status)) {
            $num = (int) $status;
            return self::$numericToString[$num] ?? 'in_progress';
        }

        $lower = strtolower(trim($status));
        if ($lower === 'pending') {
            return 'under_contract';
        }
        if ($lower === 'active') {
            return 'published';
        }

        return $lower;
    }

    /**
     * Convert status to Reozom numeric integer code.
     */
    public static function toNumericStatus(string|int $status): int
    {
        if (is_numeric($status)) {
            return (int) $status;
        }

        return self::$stringToNumeric[strtolower(trim($status))] ?? Listing::STATUS_ACTIVE_WAITING_FOR_APPROVAL;
    }

    /**
     * Check if a transition is logically valid under Reozom rules.
     */
    public static function canTransition(string|int $fromStatus, string|int $toStatus): bool
    {
        $from = self::normalizeStatus($fromStatus);
        $to = self::normalizeStatus($toStatus);

        if ($from === $to) {
            return true;
        }

        $allowed = self::$transitions[$from] ?? [];
        return in_array($to, $allowed, true);
    }

    /**
     * Transition a listing to a new status with validation, concurrency locking, and audit logging.
     * Replicates Reozom ListingsUpdateStatusService and Administration ListingsController@changeStatus.
     *
     * @throws ValidationException
     */
    public function transition(
        Listing $listing,
        string|int $newStatusInput,
        ?User $actor = null,
        string $actorRole = 'system',
        ?string $comment = null,
        ?array $metadata = null,
        bool $forceAdminOverride = false
    ): Listing {
        $canonicalNewStatus = self::normalizeStatus($newStatusInput);

        return DB::transaction(function () use ($listing, $canonicalNewStatus, $actor, $actorRole, $comment, $metadata, $forceAdminOverride) {
            // Lock listing record for concurrency safety
            /** @var Listing $lockedListing */
            $lockedListing = Listing::where('id', $listing->id)->lockForUpdate()->firstOrFail();
            $oldStatus = self::normalizeStatus($lockedListing->status);

            if ($oldStatus === $canonicalNewStatus) {
                return $lockedListing;
            }

            if (!$forceAdminOverride && !self::canTransition($oldStatus, $canonicalNewStatus)) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot transition listing from '{$oldStatus}' to '{$canonicalNewStatus}'."],
                ]);
            }

            // Completeness check if transitioning to published from initial review
            if ($canonicalNewStatus === 'published' && !$forceAdminOverride && !in_array($oldStatus, ['suspended', 'under_contract', 'ccs'], true)) {
                $requiredDocs = \App\Http\Controllers\Api\ListingDocumentController::getRequiredDocumentTypes($lockedListing);
                $uploadedDocs = $lockedListing->documents()->pluck('type')->toArray();
                $missingDocs = array_diff($requiredDocs, $uploadedDocs);

                if (!empty($missingDocs)) {
                    throw ValidationException::withMessages([
                        'status' => ['Cannot publish listing with missing required documents: ' . implode(', ', $missingDocs)],
                    ]);
                }
            }

            // Business logic for Pending (under_contract) or CCS (Contingent - Continue to Show)
            if (in_array($canonicalNewStatus, ['under_contract', 'ccs'], true) && !empty($metadata)) {
                $this->handlePendingOrCcsDetails($lockedListing, $metadata);
            }

            // Business logic for Sold
            if ($canonicalNewStatus === 'sold' && !empty($metadata)) {
                $this->handleSoldDetails($lockedListing, $metadata);
            }

            // Update listing status and timestamp
            $lockedListing->update([
                'status' => $canonicalNewStatus,
                'status_changed_date' => Carbon::now(),
            ]);

            // Record immutable history audit
            ListingStatusHistory::create([
                'listing_id' => $lockedListing->id,
                'from_status' => $oldStatus,
                'to_status' => $canonicalNewStatus,
                'actor_id' => $actor?->id,
                'actor_role' => $actorRole,
                'comment' => $comment ?? "Listing status updated to {$canonicalNewStatus}",
                'metadata' => $metadata,
            ]);

            return $lockedListing->refresh();
        });
    }

    /**
     * Persist closing/pending details when listing is put under contract or CCS.
     * Replicates Reozom: ListingsUpdateStatusService::createOrUpdateListingSoldInformation
     */
    protected function handlePendingOrCcsDetails(Listing $listing, array $data): void
    {
        $soldDetails = ListingSoldDetails::firstOrNew(['listing_id' => $listing->id]);

        if (!empty($data['anticipated_closed_date'])) {
            $soldDetails->anticipated_closed_date = Carbon::parse($data['anticipated_closed_date']);
        }
        if (isset($data['sales_price'])) {
            $soldDetails->sales_price = (float) $data['sales_price'];
        }
        if (isset($data['sellers_concessions'])) {
            $soldDetails->sellers_concessions = (int) $data['sellers_concessions'];
        }
        if (isset($data['amount'])) {
            $soldDetails->amount = (float) $data['amount'];
        }
        if (!empty($data['finance_type'])) {
            $soldDetails->finance_type = $data['finance_type'];
        }
        if (isset($data['agent'])) {
            $soldDetails->agent = (int) $data['agent'];
        }
        if (!empty($data['agent_name'])) {
            $soldDetails->agent_name = $data['agent_name'];
        }
        if (!empty($data['company'])) {
            $soldDetails->company = $data['company'];
        }
        if (!empty($data['purchase_agreement'])) {
            $soldDetails->purchase_agreement = $data['purchase_agreement'];
        }

        $soldDetails->save();
    }

    /**
     * Persist final transaction and closing details when listing is marked sold.
     * Replicates Reozom: ListingsUpdateStatusService::statusIsChangedToSold
     */
    protected function handleSoldDetails(Listing $listing, array $data): void
    {
        $soldDetails = ListingSoldDetails::firstOrNew(['listing_id' => $listing->id]);

        if (!empty($data['closed_date'])) {
            $soldDetails->closed_date = Carbon::parse($data['closed_date']);
        }
        if (isset($data['sales_price'])) {
            $soldDetails->sales_price = (float) $data['sales_price'];
        }
        if (isset($data['sellers_concessions'])) {
            $soldDetails->sellers_concessions = (int) $data['sellers_concessions'];
        }
        if (isset($data['amount'])) {
            $soldDetails->amount = (float) $data['amount'];
        }
        if (!empty($data['finance_type'])) {
            $soldDetails->finance_type = $data['finance_type'];
        }
        if (isset($data['agent'])) {
            $soldDetails->agent = (int) $data['agent'];
        }
        if (!empty($data['agent_name'])) {
            $soldDetails->agent_name = $data['agent_name'];
        }
        if (!empty($data['company'])) {
            $soldDetails->company = $data['company'];
        }
        if (!empty($data['closing_document'])) {
            $soldDetails->closing_document = $data['closing_document'];
        }

        $soldDetails->save();
    }
}
