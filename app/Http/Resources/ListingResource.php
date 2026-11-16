<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ListingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primaryImage = $this->relationLoaded('images')
            ? ($this->images->firstWhere('is_primary', true) ?? $this->images->first())
            : ($this->images()->where('is_primary', true)->first() ?? $this->images()->orderBy('sort_order')->first());

        // Values come from the listing steps (see Listing::PUBLIC_FIELD_SOURCES); listings created
        // with the old "Public Listing Information" step still read that step first.
        $price = $this->publicValue('listing_price');
        $bedrooms = $this->publicValue('bedrooms');
        $bathrooms = $this->publicValue('bathrooms');
        $squareFeet = $this->publicValue('square_feet');
        $propertyType = $this->publicPropertyType();
        $title = $this->publicTitle();
        $remarks = $this->publicValue('remarks');
        $directions = $this->publicValue('directions');
        $compensation = $this->publicValue('compensation');
        $compensationType = $this->publicValue('compensation_type');
        $directBuyerRebate = $this->publicValue('direct_buyer_rebate');
        $sellerConcessions = $this->publicValue('seller_concessions');
        $yearBuilt = $this->publicValue('year_built');

        return [
            'id' => $this->id,
            'referenceCode' => $this->reference_code,
            'status' => $this->status,
            'address' => [
                'address' => $this->address,
                'city' => $this->city,
                'state' => $this->state,
                'zip' => $this->zip,
            ],
            'progressPercent' => $this->progressPercent(),
            'stepsCompleted' => $this->steps_completed,
            'stepsTotal' => $this->steps_total,
            'updatedAt' => $this->updated_at->diffForHumans(),
            'primaryPhotoUrl' => $primaryImage?->thumbnail_url ?? $primaryImage?->url,
            'offersCount' => $this->relationLoaded('offers') ? $this->offers->count() : $this->offers()->count(),
            'packageName' => $this->servicePackage?->name,
            'packagePrice' => $this->servicePackage?->price !== null ? (float) $this->servicePackage->price : null,
            'statusChangedDate' => $this->status_changed_date?->toISOString(),
            'price' => $price ? (float)$price : null,
            'bedrooms' => $bedrooms !== null ? (int)$bedrooms : null,
            'bathrooms' => $bathrooms !== null ? (float)$bathrooms : null,
            'squareFeet' => $squareFeet !== null ? (int)$squareFeet : null,
            'propertyType' => $propertyType,
            'listingTitle' => $title,
            'remarks' => $remarks,
            'directions' => $directions,
            'compensation' => $compensation !== null ? (float)$compensation : null,
            'compensationType' => $compensationType !== null ? (int)$compensationType : 1,
            'directBuyerRebate' => $directBuyerRebate !== null ? (float)$directBuyerRebate : null,
            'sellerConcessions' => $sellerConcessions !== null ? (float)$sellerConcessions : null,
            'userId' => $this->user_id,
            'isOwner' => $request->user() ? ((int) $this->user_id === (int) $request->user()->id) : false,
            'canMakeOffer' => $request->user()
                ? ((int) $this->user_id !== (int) $request->user()->id && in_array($this->status, ['published', 'under_contract', 'ccs'], true))
                : in_array($this->status, ['published', 'under_contract', 'ccs'], true),
            'yearBuilt' => $yearBuilt !== null ? (int)$yearBuilt : null,
        ];
    }
}
