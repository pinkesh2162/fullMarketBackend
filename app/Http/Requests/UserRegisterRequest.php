<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UserRegisterRequest extends FormRequest
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
            'name'       => 'nullable|string|max:255',
            'first_name'    => 'required_without:name|string|max:255',
            'last_name'     => 'nullable|string|max:255',
            'email'         => 'required|string|email|max:255|unique:users',
            'password'      => 'required|string|min:8',
            'phone'         => 'nullable|string|max:20',
            'phone_code'    => 'nullable|string|max:10',
            'location'      => 'nullable|string|max:255',
            'description'   => 'nullable|string',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'lang'          => 'nullable|string|max:10',
        ];
    }
}
