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

        return $this->actionSuccess('profile_retrieved', $user);
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

        return $this->actionSuccess('profile_updated', ['user' => $user]);
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

        return $this->actionSuccess('account_deleted');
    }

    /**
     * Get the authenticated user's settings.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function getSettings(Request $request): JsonResponse
    {
        $settings = $request->user()->settings()->firstOrCreate([]);

        return $this->actionSuccess('settings_retrieved', $settings);
    }

    /**
     * Update the authenticated user's settings.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $settings = $request->user()->settings()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->only([
                'hide_ads',
                'notification_post',
                'message_notification',
                'business_create',
                'follow_request'
            ])
        );

        return $this->actionSuccess('settings_updated', $settings);
    }

    /**
     * Toggle the authenticated user's hide_ads preference.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function toggleHideAds(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->settings()->firstOrCreate([]);
        $settings->update(['hide_ads' => !$settings->hide_ads]);

        return $this->actionSuccess('preference_updated', ['hide_ads' => $settings->hide_ads]);
    }
}
