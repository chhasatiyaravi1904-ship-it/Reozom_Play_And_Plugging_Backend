<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $location = collect([$this->city, $this->state])->filter()->implode(', ');

        return [
            'id' => $this->id,
            'fullName' => $this->name,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'isActive' => $this->is_active,
            'emailVerified' => $this->hasVerifiedEmail(),
            'streetAddress' => $this->street_address,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'location' => $location !== '' ? $location : null,
            'company' => $this->company,
            'officeNumber' => $this->office_number,
            'extension' => $this->extension,
            'profileFinished' => $this->profile_finished,
            'hasActivePackage' => $this->currentAgentPackage !== null,
            'currentPackage' => $this->currentAgentPackage ? [
                'id' => $this->currentAgentPackage->package_id,
                'name' => $this->currentAgentPackage->package?->name,
                'slug' => $this->currentAgentPackage->package?->slug,
                'maxListingProcesses' => $this->currentAgentPackage->package?->max_listing_processes,
                'startedAt' => $this->currentAgentPackage->started_at,
                'expiresAt' => $this->currentAgentPackage->expires_at,
            ] : null,
            'listingsCount' => $this->whenCounted('listings'),
            'lastActiveAt' => $this->whenLoaded('loginLogs', fn () => $this->loginLogs->first()?->created_at),
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
