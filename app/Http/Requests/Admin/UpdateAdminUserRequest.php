<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminUserRequest extends FormRequest
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
        $id = (int) $this->route('id');

        return [
            'name' => 'nullable|string|max:255',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)->whereNull('deleted_at')],
            'password' => 'nullable|string|min:6|max:255',
            'phone' => 'nullable|string|max:30',
            'phone_code' => 'nullable|string|max:10',
            'registered_from' => ['nullable', Rule::in([User::REGISTERED_FROM_ANDROID, User::REGISTERED_FROM_IOS, User::REGISTERED_FROM_WEB])],
            'location' => 'nullable|array',
            'description' => 'nullable|string|max:5000',
            'account_status' => ['nullable', Rule::in([User::ACCOUNT_STATUS_ACTIVE, User::ACCOUNT_STATUS_SUSPEND, User::ACCOUNT_STATUS_BLOCKED])],
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
        ];
    }
}
