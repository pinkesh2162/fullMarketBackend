<?php

namespace App\Http\Resources;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\User $user */
        $user = $this->resource;
        $user->loadMissing(['store', 'stores']);
        $store = $user->store ?? $user->stores->first();

        return [
            'id' => $user->id,
            'role' => (string) ($user->getAttributes()['role'] ?? \App\Models\User::ROLE_USER),
            'registered_from' => (string) ($user->getAttributes()['registered_from'] ?? \App\Models\User::REGISTERED_FROM_WEB),
            'unique_key' => $user->unique_key,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'phone_code' => $user->phone_code,
            'location' => $user->location,
            'description' => $user->description,
            'profile_photo' => $user->profile_photo,
            'createdAt' => $user->created_at,
            'display_name' => $user->social_name,
            'name' => $user->social_name,
            'has_store' => $store instanceof Store,
            'store_id' => $store?->id,
            'store_name' => $store?->name,
        ];
    }
}
