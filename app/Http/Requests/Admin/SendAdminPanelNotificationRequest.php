<?php

namespace App\Http\Requests\Admin;

use App\Services\AdminPush\UserSegmentQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendAdminPanelNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->title) ? trim($this->title) : '',
            'description' => is_string($this->description) ? trim($this->description) : '',
            'segmentId' => is_string($this->segmentId) && trim($this->segmentId) !== '' ? trim($this->segmentId) : 'all',
            'country' => is_string($this->country) && trim($this->country) !== '' ? trim($this->country) : 'all',
            'city' => is_string($this->city) && trim($this->city) !== '' ? trim($this->city) : 'all',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:500'],
            'segmentId' => ['required', 'string', Rule::in(UserSegmentQuery::SEGMENTS)],
            'country' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'dryRun' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toBroadcastPayload(): array
    {
        return [
            'title' => (string) $this->input('title'),
            'body' => (string) $this->input('description'),
            'segmentId' => (string) $this->input('segmentId', 'all'),
            'countryFilter' => (string) $this->input('country', 'all'),
            'cityFilter' => (string) $this->input('city', 'all'),
            'dryRun' => $this->boolean('dryRun'),
            'metadata' => [
                'channel' => 'admin_panel',
            ],
        ];
    }
}
