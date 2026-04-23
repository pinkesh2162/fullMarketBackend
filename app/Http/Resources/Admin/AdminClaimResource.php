<?php

namespace App\Http\Resources\Admin;

use App\Models\Claim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminClaimResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Claim $c */
        $c = $this->resource;
        $images = $c->getMedia(Claim::CLAIM_IMAGES)->map(fn ($m) => $m->getFullUrl())->values()->all();

        return [
            'id' => $c->id,
            'status' => (string) ($c->status ?? Claim::STATUS_PENDING),
            'claim_type' => (int) $c->claim_type,
            'claim_type_label' => (int) $c->claim_type === Claim::CLAIM ? 'claim_add' : 'claim_remove',
            'full_name' => $c->full_name,
            'email' => $c->email,
            'phone' => $c->phone,
            'phone_code' => $c->phone_code,
            'description' => $c->description,
            'user_id' => $c->user_id,
            'user_name' => $c->user?->first_name ? trim(($c->user?->first_name ?? '').' '.($c->user?->last_name ?? '')) : null,
            'listing_id' => $c->listing_id,
            'listing_title' => $c->listing?->title,
            'listing_store' => $c->listing?->store?->name,
            'listing_category' => $c->listing?->category?->name,
            'listing_thumbnail' => $c->listing?->images[0] ?? null,
            'images' => $images,
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }
}
