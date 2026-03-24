<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EditProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:users,email,' . $this->user()->id,
            'phone' => 'nullable|string|max:20',
            'phone_code' => 'nullable|string|max:10',
            'location' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'file' => 'nullable|image|max:5120',
        ];
    }
}
