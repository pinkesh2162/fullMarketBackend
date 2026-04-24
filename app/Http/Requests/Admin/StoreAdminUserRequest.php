<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required_without_all:first_name,last_name|string|max:255',
            'first_name' => 'required_without:name|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,NULL,id,deleted_at,NULL',
            'password' => 'required|string|min:6|max:255',
            'phone' => 'nullable|string|max:30',
            'phone_code' => 'nullable|string|max:10',
            'registered_from' => ['nullable', Rule::in([User::REGISTERED_FROM_ANDROID, User::REGISTERED_FROM_IOS, User::REGISTERED_FROM_WEB])],
        ];
    }
}
