<?php

namespace App\Http\Resources\Admin;

use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Listing $l */
        $l = $this->resource;
        $images = $l->images;
        $thumb = is_countable($images) && count($images) > 0 ? $images[0] : null;

        return [
            'id' => $l->id,
            'public_id' => 'LIST-'.str_pad((string) $l->id, 7, '0', STR_PAD_LEFT),
            'firebase_id' => $l->firebase_id,
            'title' => $l->title,
            'store' => $l->store?->name,
            'store_id' => $l->store_id,
            'category' => $l->category?->name,
            'category_id' => $l->service_category,
            'service_type' => $l->service_type,
            'price' => $l->price,
            'currency' => $l->currency,
            'price_label' => $this->formatPriceLabel($l),
            'views_count' => (int) $l->views_count,
            'views_trend' => null,
            'expiration' => $this->inferExpiration($l),
            'status' => $this->rowStatus($l),
            'is_featured' => (bool) $l->is_featured,
            'featured_at' => $l->featured_at?->toIso8601String(),
            'reports_count' => (int) ($l->reports_count ?? 0),
            'created_at' => $l->created_at?->toIso8601String(),
            'updated_at' => $l->updated_at?->toIso8601String(),
            'deleted_at' => $l->deleted_at?->toIso8601String(),
            'thumbnail' => $thumb,
            'user_id' => $l->user_id,
        ];
    }

    private function rowStatus(Listing $l): string
    {
        if ($l->trashed()) {
            return 'deleted';
        }
        if ($l->availability === false) {
            return 'expired';
        }

        return 'active';
    }

    private function inferExpiration(Listing $l): ?string
    {
        $add = $l->additional_info ?? [];
        $adv = $l->advance_options ?? [];
        $raw = data_get($add, 'endDate')
            ?? data_get($add, 'expires_at')
            ?? data_get($add, 'expiration')
            ?? data_get($adv, 'endDate')
            ?? data_get($adv, 'ends_at');
        if (is_string($raw) && $raw !== '') {
            return $raw;
        }

        return null;
    }

    private function formatPriceLabel(Listing $l): string
    {
        $p = (string) ($l->price ?? '');
        if ($p === '' || $p === '0' || strcasecmp($p, 'free') === 0) {
            return 'Free';
        }
        $cur = (string) ($l->currency ?? '');

        return $cur !== '' ? $cur.$p : $p;
    }
}
