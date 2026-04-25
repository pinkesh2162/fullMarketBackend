<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\User $u */
        $u = $this->resource;
        $loc = is_array($u->location) ? $u->location : [];
        $country = $loc['country'] ?? $loc['countryName'] ?? $loc['country_name'] ?? null;
        $data = is_array($u->data) ? $u->data : [];

        return [
            'id' => $u->id,
            'public_id' => $u->unique_key ? 'USR-'.$u->unique_key : null,
            'unique_key' => $u->unique_key,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'name' => trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: null,
            'email' => $u->email,
            'phone' => $u->phone,
            'phone_code' => $u->phone_code,
            'location' => $u->location,
            'description' => $u->description,
            'profile_photo' => $u->profile_photo,
            'country' => is_string($country) ? $country : null,
            'registered_from' => $u->registered_from ?? \App\Models\User::REGISTERED_FROM_WEB,
            'os' => $this->inferOs((string) ($u->registered_from ?? ''), $data),
            'stores_count' => (int) ($u->stores_count ?? 0),
            'posts_count' => (int) ($u->listings_count ?? 0),
            'listings_count' => (int) ($u->listings_count ?? 0),
            'status' => $u->publicAccountStatusForApi(),
            'account_status' => $u->trashed() ? null : ($u->account_status ?? \App\Models\User::ACCOUNT_STATUS_ACTIVE),
            'email_verified_at' => $u->email_verified_at?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
            'registered_at' => $u->created_at?->toIso8601String(),
            'updated_at' => $u->updated_at?->toIso8601String(),
            'deleted_at' => $u->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function inferOs(string $registeredFrom, array $data): ?string
    {
        $registeredFrom = strtolower(trim($registeredFrom));
        if (in_array($registeredFrom, ['android', 'ios', 'web'], true)) {
            return $registeredFrom;
        }

        $p = strtolower((string) ($data['platform'] ?? $data['device_platform'] ?? $data['deviceType'] ?? ''));
        if ($p === '') {
            return 'web';
        }
        if (str_contains($p, 'android')) {
            return 'android';
        }
        if ($p === 'ios' || $p === 'iphone' || str_contains($p, 'ios')) {
            return 'ios';
        }

        return null;
    }
}
