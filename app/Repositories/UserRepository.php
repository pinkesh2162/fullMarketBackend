<?php

namespace App\Repositories;

use App\Exceptions\ApiOperationFailedException;
use App\Http\Resources\UserResource;
use App\Mail\VerifyEmailMail;
use App\Mail\WelcomeMail;
use App\Models\Claim;
use App\Models\Favorite;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\Rating;
use App\Models\Store;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
     * @param $request
     *
     * @throws ApiOperationFailedException
     * @return array|JsonResponse
     */
    public function registerUser($request)
    {
        try {
            return DB::transaction(function () use ($request) {
                // If a trashed account exists with the same email, permanently delete it first
                $trashedUser = User::withTrashed()->where('email', $request->email)->first();
                if ($trashedUser && $trashedUser->trashed()) {
                    $this->forceDeleteUserAccount($trashedUser);
                }

                $data = $request->except('password');
                $data['password'] = Hash::make($request->password);

                if (!empty($data['name'])) {
                    [$first, $last] = array_pad(explode(' ', trim($data['name']), 2), 2, null);
                    $data['first_name'] = $first;
                    $data['last_name'] = $last;
                    unset($data['name']);
                }


                $otp = random_int(100000, 999999);
                $data['otp'] = $otp;
                $data['otp_expires_at'] = now()->addMinutes(10);

                $user = $this->create($data);

                // Dispatch emails to queue (NON-BLOCKING ⚡)
                Mail::to($user->email)->send(new VerifyEmailMail($user->email, $otp));
                Mail::to($user->email)->queue(new WelcomeMail($user, $request->password));

                return [
                    'user' => $user,
                    'message' => 'registration_success',
                ];
            });
        } catch (\Exception $e) {
            throw new ApiOperationFailedException($e->getMessage(), 422);
        }
    }

    /**
     * Find user by email.
     *
     * @param  string  $email
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

    /**
     * @param string $provider
     * @param array $appSocialUser
     * @return User
     */
    public function handleAppSocialLogin(string $provider, array $appSocialUser)
    {
        $userInfo = $appSocialUser['userInfo'] ?? [];
        $providerId = $userInfo['sub'] ?? null;
        $email = $userInfo['email'] ?? null;

        $user = User::with('media')->where('provider', $provider)
            ->where('provider_id', $providerId)->first();

        if (!$user && $email) {
            $user = User::with('media')->where('email', $email)->first();
            if ($user) {
                // Link account
                $user->update([
                    'provider' => $provider,
                    'provider_id' => $providerId,
                ]);
            }
        }

        if (!$user) {
            $firstName = $userInfo['givenName'] ?? null;
            $lastName = null;

            $name = $userInfo['name'] ?? null;
            $nickname = $userInfo['nickname'] ?? null;

            // If name is present but looks like an email, try to use nickname or part of email
            if ($name && filter_var($name, FILTER_VALIDATE_EMAIL)) {
                $name = $nickname ?: Str::before($name, '@');
            }

            if (!$firstName) {
                if ($name) {
                    $parts = explode(' ', trim($name), 2);
                    $firstName = $parts[0];
                    $lastName = $parts[1] ?? null;
                } else {
                    $firstName = $nickname ?: 'User';
                }
            } else if ($name && $firstName) {
                $lastName = trim(str_replace($firstName, '', $name));
                if (empty($lastName)) $lastName = null;
            }

            $user = $this->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'provider' => $provider,
                'provider_id' => $providerId,
                'password' => Hash::make(Str::random(24)),
                'email_verified_at' => ($userInfo['emailVerified'] ?? false) ? now() : null,
            ]);
        }

        // Handle profile picture from URL if provided and user doesn't have one or it changed
        if (isset($userInfo['picture']) && !empty($userInfo['picture'])) {
            // We can optionally check if media collection is empty or if we want to update it
            if ($user->getMedia(User::PROFILE)->isEmpty()) {
                try {
                    $user->addMediaFromUrl($userInfo['picture'])->toMediaCollection(User::PROFILE, config('app.media_disc', 'public'));
                } catch (\Exception $e) {
                    // Log error or ignore if image download fails
                    \Illuminate\Support\Facades\Log::error("Failed to download social profile picture: " . $e->getMessage());
                }
            }
        }

        return $user;
    }

    /**
     * @param $request
     *
     * @throws ApiOperationFailedException
     * @return JsonResponse
     */
    public function handleResetPassword($request): void
    {

        $reset = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$reset || !Hash::check($request->token, $reset->token)) {
            throw new ApiOperationFailedException('invalid_reset_token', 400);
        }

        if (\Carbon\Carbon::parse($reset->created_at)->addMinutes(30)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            throw new ApiOperationFailedException('reset_token_expired', 400);
        }

        $user = $this->findByEmail($request->email);
        if (!$user) {
            throw new ApiOperationFailedException('user_not_found', 404);
        }

        $user->update(['password' => Hash::make($request->password)]);

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();
    }

    /**
     * @param $email
     * @param $otp
     *
     * @throws ApiOperationFailedException
     *
     * @return array
     */
    public function verifyOtp($email, $otp)
    {
        $user = User::with('media')->where('email', $email)->first();

        if (!$user) {
            throw new ApiOperationFailedException('user_not_found', 404);
        }

        if ($user->email_verified_at) {
            throw new ApiOperationFailedException('email_already_verified', 400);
        }

        if ($user->otp !== $otp) {
            throw new ApiOperationFailedException('invalid_otp', 400);
        }

        if (now()->isAfter($user->otp_expires_at)) {
            throw new ApiOperationFailedException('otp_expired', 400);
        }

        $user->update([
            'email_verified_at' => now(),
            'otp' => null,
            'otp_expires_at' => null,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return ['user' => UserResource::make($user), 'token' => $token];
    }

    /**
     * @param $email
     *
     * @throws ApiOperationFailedException
     *
     * @return bool
     */
    public function resendOtp($email)
    {
        $user = $this->findByEmail($email);

        if (!$user) {
            throw new ApiOperationFailedException('user_not_found', 404);
        }

        if ($user->email_verified_at) {
            throw new ApiOperationFailedException('email_already_verified', 400);
        }

        $otp = rand(100000, 999999);
        $user->update([
            'otp' => $otp,
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        Mail::to($user->email)->queue(new VerifyEmailMail($user->email, $otp));

        return true;
    }

    /**
     * @param  User  $user
     *
     * @throws ApiOperationFailedException
     * @return bool
     */
    public function deleteUserAccount(User $user): bool
    {
        DB::beginTransaction();
        try {
            if ($user->store) {
                // Prevent database ON DELETE CASCADE from hard-deleting the soft-deletable listings
                Listing::where('user_id', $user->id)->update(['store_id' => null]);
                $user->store->delete();
            }

            foreach ($user->listings as $listing) {
                $listing->delete();
            }

            foreach ($user->claims as $claim) {
                $claim->delete();
            }

            Favorite::where('user_id', $user->id)->delete();
            $user->tokens()->delete();

            $user->delete();
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ApiOperationFailedException($e->getMessage(), 422);
        }
    }

    /**
     * Permanently delete user account and all associated data.
     *
     * @param User $user
     * @return bool
     * @throws ApiOperationFailedException
     */
    public function forceDeleteUserAccount(User $user): bool
    {
        DB::beginTransaction();
        try {
            // 1. Delete Store and its ratings
            if ($user->store) {
                Rating::where('store_id', $user->store->id)->delete();
                $user->store->delete(); // Store doesn't use SoftDeletes
            }

            // 2. Force delete Listings, Claims, and Favorites (these use SoftDeletes)
            Listing::where('user_id', $user->id)->withTrashed()->forceDelete();
            Claim::where('user_id', $user->id)->withTrashed()->forceDelete();
            Favorite::where('user_id', $user->id)->withTrashed()->forceDelete();

            // 3. Delete other associated data
            // Rating::where('user_id', $user->id)->delete();
            UserSetting::where('user_id', $user->id)->delete();
            Notification::where('user_id', $user->id)->delete();

            // 4. Delete follows
            DB::table('follows')->where('user_id', $user->id)->delete();
            if ($user->store) {
                DB::table('follows')->where('store_id', $user->store->id)->delete();
            }

            // 5. Delete Sanctum tokens
            $user->tokens()->delete();

            // 6. Force delete the user
            $user->forceDelete();

            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ApiOperationFailedException($e->getMessage(), 422);
        }
    }
}
