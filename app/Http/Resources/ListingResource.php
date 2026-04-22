<?php

namespace App\Http\Resources;

use App\Models\Claim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Repositories\ChatRepository;

class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = auth('sanctum')->user();
        $listing = $this->resource;

        return [
            'id' => $this->id,
            'user' => $this->user ? new UserResource($this->user) : null,
            'store' => $this->store ? new StoreResource($this->store) : null,
            'category' => $this->category ? new CategoryResource($this->category) : null,
            'service_type' => $this->service_type,
            'title' => $this->title,
            'service_modality' => $this->service_modality,
            'description' => $this->description,
            'search_keyword' => $this->search_keyword,
            'contact_info' => $this->contact_info,
            'additional_info' => $this->additional_info,
            'currency' => $this->currency,
            'price' => $this->price,
            'availability' => $this->availability,
            'condition' => $this->condition,
            'listing_type' => $this->listing_type,
            'property_type' => $this->property_type,
            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'advance_options' => $this->advance_options,
            'address' => $this->address,
            'vehicle_type' => $this->vehicle_type,
            'vehical_info' => $this->vehical_info,
            'fual_type' => $this->fual_type,
            'transmission' => $this->transmission,
            'images' => $this->images,
            'is_claim_added' => $listing->relationLoaded('claims')
                ? $listing->claims->contains(fn (Claim $c) => (int) $c->claim_type === Claim::CLAIM)
                : $listing->claims()->where('claim_type', Claim::CLAIM)->exists(),
            'is_claim_removed' => $listing->relationLoaded('claims')
                ? $listing->claims->contains(fn (Claim $c) => (int) $c->claim_type === Claim::REMOVE_AD)
                : $listing->claims()->where('claim_type', Claim::REMOVE_AD)->exists(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Optional auth: send Authorization: Bearer on GET get/listing/{id} (see OptionalSanctumAuth).
            'has_messaged_seller' => $user
                ? app(ChatRepository::class)->hasViewerMessagedListingSeller($user, $this->resource)
                : false,
            // 'has_messaged_for_this_listing' => $user
            //     ? app(ChatRepository::class)->hasViewerMessagedForThisListing($user, $this->resource)
            //     : false,
        ];
    }
}
