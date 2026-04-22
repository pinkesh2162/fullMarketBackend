<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClaimRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'listing_id' => 'required|exists:listings,id',
            'claim_type' => 'required|boolean',
            'full_name'  => 'required|string|max:255',
            'email'      => 'required|email|max:255',
            'phone'      => 'required|string|max:20',
            'phone_code' => 'required|string|max:10',
            'description' => 'nullable|string',
            'image'     => 'nullable|image|max:40960',
        ];
    }
}
