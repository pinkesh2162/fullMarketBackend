<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreListingRequest extends FormRequest
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
            'service_type' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'service_category' => 'nullable|exists:categories,id',
            'service_modality' => 'nullable|string',
            'description' => 'nullable|string',
            'search_keyword' => 'nullable|string',
            'contact_info' => 'nullable|array',
            'additional_info' => 'nullable|array',
            'currency' => 'nullable|string',
            'price' => 'nullable|string',
            'availability' => 'nullable|boolean',
            'condition' => 'nullable|string',
            'listing_type' => 'nullable|string',
            'property_type' => 'nullable|string',
            'bedrooms' => 'nullable|string',
            'bathrooms' => 'nullable|string',
            'advance_options' => 'nullable|array',
            'vehicle_type' => 'nullable|string',
            'vehical_info' => 'nullable|array',
            'fual_type' => 'nullable|string',
            'transmission' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'image|max:2048',
        ];
    }
}
