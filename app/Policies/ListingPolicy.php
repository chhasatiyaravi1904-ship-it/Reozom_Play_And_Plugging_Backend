<?php

namespace App\Policies;

use App\Models\Listing;
use App\Models\User;

class ListingPolicy
{
    /**
     * Determine whether the user can view any listings.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the listing.
     * Owners and Admins can view private drafts.
     * Anyone can view if it is published.
     */
    public function view(User $user, Listing $listing): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($listing->user_id === $user->id) {
            return true;
        }

        if ($user->isAgent() && $listing->servicePackage && $listing->servicePackage->agent_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create listings.
     * Sellers can create listings.
     * Agents can create listings only if they have an active package.
     */
    public function create(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isAgent()) {
            return $user->hasActivePackage();
        }

        return $user->isSeller() || $user->isBuyer();
    }

    /**
     * Determine whether the user can update the listing.
     */
    public function update(User $user, Listing $listing): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $listing->user_id === $user->id;
    }

    /**
     * Determine whether the user can delete the listing.
     */
    public function delete(User $user, Listing $listing): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $listing->user_id === $user->id;
    }

    /**
     * Determine whether the user can submit the listing for publication.
     */
    public function submit(User $user, Listing $listing): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $listing->user_id === $user->id;
    }

    /**
     * Determine whether the user can moderate (publish, unpublish, reject) the listing.
     */
    public function moderate(User $user): bool
    {
        return $user->isAdmin();
    }
}
