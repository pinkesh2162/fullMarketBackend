<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
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
            'user_id' => $this->user_id,
            'name' => $this->localizedDisplayName(),
            'parent_id' => $this->parent_id,
            'image' => $this->categoryImage,
            'sub_categories' => CategoryResource::collection($this->whenLoaded('subCategories')),
            'used_in_listings_count' => (int) ($this->resource->getAttribute('listings_count') ?? 0),
            // 'created_at' => $this->created_at,
            // 'updated_at' => $this->updated_at,
        ];
    }
}
