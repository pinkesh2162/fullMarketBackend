<?php

namespace App\Http\Requests\Admin;

use App\Services\AdminPush\UserSegmentQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendAdminPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->title) ? trim($this->title) : '',
            'body' => is_string($this->body) ? trim($this->body) : '',
        ]);
        $this->mergeIfMissing([
            'countryFilter' => 'all',
            'cityFilter' => 'all',
        ]);
        if (is_string($this->countryFilter)) {
            $this->merge(['countryFilter' => trim($this->countryFilter)]);
        }
        if (is_string($this->cityFilter)) {
            $this->merge(['cityFilter' => trim($this->cityFilter)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:500'],
            'segmentId' => ['required', 'string', Rule::in(UserSegmentQuery::SEGMENTS)],
            'countryFilter' => ['nullable', 'string', 'max:120'],
            'cityFilter' => ['nullable', 'string', 'max:120'],
            'campaignId' => ['nullable', 'string', 'max:128'],
            'scheduledAt' => ['nullable'],
            'metadata' => ['nullable', 'array'],
            'dryRun' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (trim((string) $this->title) === '') {
                $v->errors()->add('title', 'The title may not be empty.');
            }
            if (trim((string) $this->body) === '') {
                $v->errors()->add('body', 'The body may not be empty.');
            }
            $cid = $this->campaignId;
            if (is_string($cid) && $cid !== '' && strlen($cid) > 128) {
                $v->errors()->add('campaignId', 'The campaign id may not be greater than 128 characters.');
            }
            if (is_string($cid) && $cid !== '' && ! preg_match('/^[A-Za-z0-9_-]{1,128}$/', $cid)) {
                $v->errors()->add('campaignId', 'The campaign id may only contain letters, numbers, underscores, and hyphens.');
            }
        });
    }
}
