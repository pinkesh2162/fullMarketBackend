<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authed by sanctum middleware globally mostly
    }

    /**
     * Prepare the data for validation.
     * Fix literal quotes in form-data keys if the frontend sent them by mistake.
     */
    protected function prepareForValidation()
    {
        $input = $this->all();

        $cleanKeys = function ($array) use (&$cleanKeys) {
            if (!is_array($array)) return $array;
            $cleaned = [];
            foreach ($array as $key => $value) {
                // Remove surrounding quotes from keys (e.g. "'email'" -> "email")
                $cleanKey = trim($key, "'\"");
                $cleaned[$cleanKey] = is_array($value) ? $cleanKeys($value) : $value;
            }
            return $cleaned;
        };

        if (isset($input['contact_information']) && is_array($input['contact_information'])) {
            $input['contact_information'] = $cleanKeys($input['contact_information']);
        }

        if (isset($input['business_time']) && is_array($input['business_time'])) {
            $input['business_time'] = $cleanKeys($input['business_time']);
        }

        $this->replace($input);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'location' => 'nullable|array',

            'business_time' => 'nullable|array',
            'business_time.is_flexible' => 'nullable|boolean',
            'business_time.working_hour' => 'nullable|array',
            'business_time.working_hour.*.day' => 'nullable|string',
            'business_time.working_hour.*.is_open' => 'nullable|boolean',
            'business_time.working_hour.*.start_time' => 'nullable|string',
            'business_time.working_hour.*.end_time' => 'nullable|string',

            'contact_information' => 'nullable|array',
            'contact_information.email' => 'nullable|email',
            'contact_information.phone' => 'nullable|string',
            'contact_information.phone_code' => 'nullable|string',
            'contact_information.whatsapp_phone' => 'nullable|string',
            'contact_information.whatsapp_code' => 'nullable|string',

            'social_media' => 'nullable|array',
            'social_media.*' => 'nullable|string', // Allow any string url

            'cover_photo' => 'nullable|image|max:5120',
            'logo' => 'nullable|image|max:2048',
        ];
    }
}
