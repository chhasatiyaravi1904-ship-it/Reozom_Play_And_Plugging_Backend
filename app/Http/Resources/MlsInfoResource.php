<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MlsInfoResource extends JsonResource
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
            'mlsDirectoryId' => $this->mls_directory_id,
            'title' => $this->title,
            'countries' => $this->countries ?? [],
            'publicWebsitesTitle' => $this->public_websites_title,
            'websites' => $this->websites ?? [],
            'info' => $this->info,
            'directory' => new MlsDirectoryResource($this->whenLoaded('directory')),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
