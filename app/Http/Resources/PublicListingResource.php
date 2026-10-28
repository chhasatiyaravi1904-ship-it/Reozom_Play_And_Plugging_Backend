<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicListingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Get the extracted public listing data
        $publicData = $this->getPublicListingData();
        
        // Build an explicit key/value map for easier frontend consumption
        $extractedValues = [];
        foreach ($publicData['fields'] as $field) {
            $extractedValues[$field['id']] = $field['value'];
        }

        return [
            'id' => $this->id,
            'reference_code' => $this->reference_code,
            'status' => $this->status,
            'address' => [
                'street' => $this->address,
                'city' => $this->city,
                'state' => $this->state,
                'zip' => $this->zip,
            ],
            // Only expose the safely extracted public answers
            'public_info' => $publicData['fields'],
            'values' => $extractedValues,
            'images' => $this->whenLoaded('images', function () {
                return $this->images->map(function ($image) {
                    return [
                        'id' => $image->id,
                        'url' => $image->url,
                        'thumbnail_url' => $image->thumbnail_url,
                        'alt' => $image->alt_text ?? 'Property image',
                    ];
                });
            }, []),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
