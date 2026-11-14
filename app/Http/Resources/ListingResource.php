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

        $price = null;
        $bedrooms = null;
        $bathrooms = null;
        $squareFeet = null;
        $propertyType = null;
        $title = null;

        $publicAnswer = $this->relationLoaded('answers')
            ? $this->answers->firstWhere('step_id', 'system_public_info')
            : $this->answers()->where('step_id', 'system_public_info')->first();

        if ($publicAnswer && is_array($publicAnswer->values)) {
            $vals = $publicAnswer->values;
            $price = $vals['listing_price'] ?? $vals['list_price'] ?? $vals['price'] ?? null;
            $bedrooms = $vals['bedrooms'] ?? $vals['total_bedrooms'] ?? null;
            $bathrooms = $vals['bathrooms'] ?? $vals['total_bathrooms'] ?? null;
            $squareFeet = $vals['square_feet'] ?? $vals['ag_square_footage'] ?? null;
            $propertyType = $vals['property_type'] ?? null;
            $title = $vals['listing_title'] ?? null;
        }

        if (!$price && $this->relationLoaded('answers')) {
            foreach ($this->answers as $ans) {
                if (is_array($ans->values)) {
                    if (!$price && isset($ans->values['list_price'])) {
                        $price = $ans->values['list_price'];
                    }
                    if (!$price && isset($ans->values['listing_price'])) {
                        $price = $ans->values['listing_price'];
                    }
                }
            }
        }

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
            'statusChangedDate' => $this->status_changed_date?->toISOString(),
            'price' => $price ? (float)$price : null,
            'bedrooms' => $bedrooms !== null ? (int)$bedrooms : null,
            'bathrooms' => $bathrooms !== null ? (float)$bathrooms : null,
            'squareFeet' => $squareFeet !== null ? (int)$squareFeet : null,
            'propertyType' => $propertyType,
            'listingTitle' => $title,
        ];
    }
}
