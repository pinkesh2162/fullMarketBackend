<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\EditProfileRequest;
use App\Http\Resources\UserResource;
use App\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;
use \Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * @var UserRepository 
     */
    protected $userRepo;

    /**
     * ProfileController constructor.
     * @param  UserRepository  $userRepository
     */
    public function __construct(UserRepository $userRepository)
    {
        $this->userRepo = $userRepository;
    }

    /**
     * Get the authenticated user's profile.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function getProfile(Request $request)
    {
        $user = UserResource::make($request->user());

        return $this->actionSuccess('User profile retrieved successfully', $user);
    }

    /**
     * Update the authenticated user's profile.
     *
     * @param EditProfileRequest $request
     * @return JsonResponse
     */
    public function updateProfile(EditProfileRequest $request): JsonResponse
    {
        $validatedData = $request->validated();
        if ($request->hasFile('profile_photo')) {
            $validatedData['profile_photo'] = $request->file('profile_photo');
        }

        $user = $this->userRepo->updateProfile($request->user(), $validatedData);

        return $this->actionSuccess('Profile updated successfully.', ['user' => $user]);
    }

    /**
     * Delete the authenticated user account and all associated data.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $this->userRepo->deleteUserAccount($request->user());

        return $this->actionSuccess('Your account and all associated data have been permanently deleted.');
    }
}
