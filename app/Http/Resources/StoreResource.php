<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreResource extends JsonResource
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
            'name' => $this->name,
            'location' => $this->location,
            'business_time' => $this->business_time,
            'contact_information' => $this->contact_information,
            'social_media' => $this->social_media,
            'cover_photo_url' => $this->cover_photo,
            'logo_url' => $this->profile_photo,
            'followers_count' => $this->followers()->count(),
            'is_followed' => auth('sanctum')->check() ? $this->followers()->where('user_id', auth('sanctum')->id())->exists() : false,
            'average_rating' => (float) $this->average_rating,
            'ratings_count' => $this->ratings_count,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
