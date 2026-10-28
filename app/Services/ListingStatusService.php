<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\ListingStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ListingStatusService
{
    /**
     * Permitted state transitions matrix.
     * key: from_status => array of allowed to_status
     */
    protected static array $transitions = [
        'draft' => ['in_progress', 'canceled'],
        'in_progress' => ['submitted', 'draft', 'canceled'],
        'submitted' => ['published', 'in_progress', 'rejected', 'canceled'],
        'published' => ['under_contract', 'in_progress', 'suspended', 'withdrawn', 'canceled', 'expired', 'unpublished'],
        'under_contract' => ['sold', 'published', 'canceled'],
        'suspended' => ['published', 'withdrawn', 'canceled', 'expired'],
        'rejected' => ['in_progress', 'submitted', 'canceled'],
        'withdrawn' => ['in_progress', 'canceled'],
        'expired' => ['in_progress', 'canceled'],
        'unpublished' => ['published', 'in_progress', 'canceled'],
        'sold' => [], // Terminal state (admin override only)
        'canceled' => ['in_progress'], // Can be reopened as in_progress
    ];

    /**
     * Check if a transition is logically valid.
     */
    public static function canTransition(string $fromStatus, string $toStatus): bool
    {
        if ($fromStatus === $toStatus) {
            return true;
        }

        $allowed = self::$transitions[$fromStatus] ?? [];
        return in_array($toStatus, $allowed, true);
    }

    /**
     * Transition a listing to a new status with validation, locking, and audit logging.
     *
     * @throws ValidationException
     */
    public function transition(
        Listing $listing,
        string $newStatus,
        ?User $actor = null,
        string $actorRole = 'system',
        ?string $comment = null,
        ?array $metadata = null,
        bool $forceAdminOverride = false
    ): Listing {
        return DB::transaction(function () use ($listing, $newStatus, $actor, $actorRole, $comment, $metadata, $forceAdminOverride) {
            // Lock listing record for update
            /** @var Listing $lockedListing */
            $lockedListing = Listing::where('id', $listing->id)->lockForUpdate()->firstOrFail();
            $oldStatus = $lockedListing->status;

            if ($oldStatus === $newStatus) {
                return $lockedListing;
            }

            if (!$forceAdminOverride && !self::canTransition($oldStatus, $newStatus)) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot transition listing from '{$oldStatus}' to '{$newStatus}'."],
                ]);
            }

            // Completeness check if transitioning to published
            if ($newStatus === 'published' && !$forceAdminOverride) {
                $requiredDocs = \App\Http\Controllers\Api\ListingDocumentController::getRequiredDocumentTypes($lockedListing);
                $uploadedDocs = $lockedListing->documents()->pluck('type')->toArray();
                $missingDocs = array_diff($requiredDocs, $uploadedDocs);

                if (!empty($missingDocs)) {
                    throw ValidationException::withMessages([
                        'status' => ['Cannot publish listing with missing required documents: ' . implode(', ', $missingDocs)],
                    ]);
                }
            }

            // Update listing
            $lockedListing->update(['status' => $newStatus]);

            // Record history
            ListingStatusHistory::create([
                'listing_id' => $lockedListing->id,
                'from_status' => $oldStatus,
                'to_status' => $newStatus,
                'actor_id' => $actor?->id,
                'actor_role' => $actorRole,
                'comment' => $comment,
                'metadata' => $metadata,
            ]);

            return $lockedListing->refresh();
        });
    }
}
