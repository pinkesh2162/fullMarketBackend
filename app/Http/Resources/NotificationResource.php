<?php

namespace App\Http\Resources;

use App\Support\PushNotificationMessages;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $localized = PushNotificationMessages::localizedFromStoredNotification($this->resource);

        return [
            'id' => $this->id,
            'title' => $localized['title'],
            'body' => $localized['body'],
            'data' => $this->data,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
