<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ZipCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'state_id' => $this->state_id,
            'county_id' => $this->county_id,
            'city_id' => $this->city_id,
            'state' => new StateResource($this->whenLoaded('state')),
            'county' => new CountyResource($this->whenLoaded('county')),
            'city' => new CityResource($this->whenLoaded('city')),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
