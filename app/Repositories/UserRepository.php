<?php

namespace App\Repositories;

use App\Models\User;

class UserRepository
{
    /**
     * @var User
     */
    protected $model;

    /**
     * UserRepository constructor.
     *
     * @param User $model
     */
    public function __construct(User $model)
    {
        $this->model = $model;
    }

    /**
     * Create a new user.
     *
     * @param array $data
     * @return User
     */
    public function create(array $data)
    {
        return $this->model->create($data);
    }

    /**
     * Find user by email.
     *
     * @param string $email
     * @return User|null
     */
    public function findByEmail(string $email)
    {
        return $this->model->where('email', $email)->first();
    }

    /**
     * Update user profile.
     *
     * @param User $user
     * @param array $data
     * @return User
     */
    public function updateProfile(User $user, array $data)
    {
        // Update user attributes
        $user->update([
            'first_name' => $data['first_name'] ?? $user->first_name,
            'last_name' => $data['last_name'] ?? $user->last_name,
            'email' => $data['email'] ?? $user->email,
            'phone' => $data['phone'] ?? $user->phone,
            'phone_code' => $data['phone_code'] ?? $user->phone_code,
            'location' => $data['location'] ?? $user->location,
            'description' => $data['description'] ?? $user->description,
        ]);

        // Handle profile image upload
        if (isset($data['profile_photo']) && $data['profile_photo'] instanceof \Illuminate\Http\UploadedFile) {
            $user->clearMediaCollection(User::PROFILE);
            $user->addMedia($data['profile_photo'])->toMediaCollection(User::PROFILE, config('app.media_disc', 'public'));
        }

        return $user;
    }
}
