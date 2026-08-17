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
        ];
    }
}
