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
use App\Models\User;
use App\Models\UserSetting;
use Exception;
use Illuminate\Container\Container as Application;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserRepository extends BaseRepository
{
    /**
     * @throws Exception
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    /**
     * @return array
     */
    public function getFieldsSearchable()
    {
        return [
            'first_name',
            'last_name',
            'email',
            'phone',
        ];
    }

    /**
     * @return string
     */
    public function model()
    {
        return User::class;
    }

    /**
     * @return array|JsonResponse
     *
     * @throws ApiOperationFailedException
     */
    public function registerUser($request)
    {
        try {
            return DB::transaction(function () use ($request) {
                // If a trashed account exists with the same email, permanently delete it first
                $trashedUser = $this->model->withTrashed()->where('email', $request->email)->first();
                if ($trashedUser && $trashedUser->trashed()) {
                    $this->forceDeleteUserAccount($trashedUser);
                }

                $data = $request->except('password');
                $data['password'] = Hash::make($request->password);

                if (! empty($data['name'])) {
                    [$first, $last] = array_pad(explode(' ', trim($data['name']), 2), 2, null);
                    $data['first_name'] = $first;
                    $data['last_name'] = $last;
                    unset($data['name']);
                }

                $otp = random_int(100000, 999999);
                $data['otp'] = $otp;
                $data['otp_expires_at'] = now()->addMinutes(10);
                $data['unique_key'] = $this->generateUniqueUserKey();
                $data['role'] = User::ROLE_USER;
                $data['registered_from'] = $this->resolveRegisteredFromRequest($request);

                $user = $this->create($data);

                if (! app()->environment('local')) {
                    Mail::to($user->email)->send(new VerifyEmailMail($user->email, $otp));
                }

                // Temporarily store password in cache for WelcomeMail (30 mins)
                Cache::put('user_pass_'.$user->email, $request->password, now()->addMinutes(30));

                return [
                    'user' => $user,
                    'message' => 'registration_success',
                ];
            });
        } catch (Exception $e) {
            throw new ApiOperationFailedException($e->getMessage(), 422);
        }
    }

    /**
     * Find user by email.
     *
     * @return User|Builder|Model|object|null
     */
    public function findByEmail(string $email)
    {
        return $this->allQuery(['email' => $email])->first();
    }

    /**
     * Update user profile.
     *
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
     * @return Builder|Model|object
     */
    public function handleAppSocialLogin(string $provider, array $appSocialUser, ?Request $request = null)
    {
        $userInfo = $appSocialUser['userInfo'] ?? [];
        $providerId = $userInfo['sub'] ?? null;
        $email = $userInfo['email'] ?? null;

        $user = User::where('provider', $provider)
            ->where('provider_id', $providerId)
            ->with('media')->first();

        if (! $user && $email) {
            $user = $this->allQuery(['email' => $email])->with('media')->first();
            if ($user) {
                // Link account
                $user->update([
                    'provider' => $provider,
                    'provider_id' => $providerId,
                ]);
            }
        }

        if (! $user) {
            $firstName = $userInfo['givenName'] ?? null;
            $lastName = null;

            $name = $userInfo['name'] ?? null;
            $nickname = $userInfo['nickname'] ?? null;

            // If name is present but looks like an email, try to use nickname or part of email
            if ($name && filter_var($name, FILTER_VALIDATE_EMAIL)) {
                $name = $nickname ?: Str::before($name, '@');
            }

            if (! $firstName) {
                if ($name) {
                    $parts = explode(' ', trim($name), 2);
                    $firstName = $parts[0];
                    $lastName = $parts[1] ?? null;
                } else {
                    $firstName = $nickname ?: 'User';
                }
            } elseif ($name && $firstName) {
                $lastName = trim(str_replace($firstName, '', $name));
                if (empty($lastName)) {
                    $lastName = null;
                }
            }

            $user = $this->create([
                'unique_key' => $this->generateUniqueUserKey(),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'provider' => $provider,
                'provider_id' => $providerId,
                'password' => Hash::make(Str::random(24)),
                'role' => User::ROLE_USER,
                'registered_from' => $request
                    ? $this->resolveRegisteredFromRequest($request)
                    : User::REGISTERED_FROM_WEB,
                'email_verified_at' => ($userInfo['emailVerified'] ?? false) ? now() : null,
            ]);
        }

        if ($request) {
            $this->syncRegisteredFromOnLogin($user, $request);
        }

        // Handle profile picture from URL if provided and user doesn't have one or it changed
        if (isset($userInfo['picture']) && ! empty($userInfo['picture'])) {
            // We can optionally check if media collection is empty or if we want to update it
            if ($user->getMedia(User::PROFILE)->isEmpty()) {
                try {
                    $user->addMediaFromUrl($userInfo['picture'])->toMediaCollection(User::PROFILE, config('app.media_disc', 'public'));
                } catch (Exception $e) {
                    // Log error or ignore if image download fails
                    \Illuminate\Support\Facades\Log::error('Failed to download social profile picture: '.$e->getMessage());
                }
            }
        }

        return $user;
    }

    /**
     * @return JsonResponse
     *
     * @throws ApiOperationFailedException
     */
    public function handleResetPassword($request): void
    {

        $reset = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (! $reset || ! Hash::check($request->token, $reset->token)) {
            throw new ApiOperationFailedException('invalid_reset_token', 400);
        }

        if (\Carbon\Carbon::parse($reset->created_at)->addMinutes(30)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            throw new ApiOperationFailedException('reset_token_expired', 400);
        }

        $user = $this->findByEmail($request->email);
        if (! $user) {
            throw new ApiOperationFailedException('user_not_found', 404);
        }

        $user->update(['password' => Hash::make($request->password)]);

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();
    }

    /**
     * @return array
     *
     * @throws ApiOperationFailedException
     */
    public function verifyOtp($email, $otp, ?Request $request = null)
    {
        $user = User::with('media')->where('email', $email)->first();

        if (! $user) {
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

        if ($request) {
            $this->syncRegisteredFromOnLogin($user, $request);
            $user->refresh();
        }

        // Retrieve temporary password if available
        $password = Cache::pull('user_pass_'.$user->email);

        if (! app()->environment('local')) {
            Mail::to($user->email)->send(new WelcomeMail($user, $password));
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return ['user' => UserResource::make($user), 'token' => $token];
    }

    /**
     * @return bool
     *
     * @throws ApiOperationFailedException
     */
    public function resendOtp($email)
    {
        $user = $this->findByEmail($email);

        if (! $user) {
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

        if (! app()->environment('local')) {
            Mail::to($user->email)->queue(new VerifyEmailMail($user->email, $otp));
        }

        return true;
    }

    /**
     * @throws ApiOperationFailedException
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
        } catch (Exception $e) {
            DB::rollBack();
            throw new ApiOperationFailedException($e->getMessage(), 422);
        }
    }

    private function generateUniqueUserKey(): string
    {
        do {
            $code = (string) random_int(1000000, 9999999);
        } while (User::where('unique_key', $code)->exists());

        return $code;
    }

    /**
     * Resolves app vs web from body fields, custom headers, and User-Agent.
     * Clients may send: registered_from, X-Registered-From, X-App-Platform, X-Client-OS, or platform inside `data` JSON.
     */
    public function resolveRegisteredFromRequest(Request $request): string
    {
        $candidates = [
            $request->input('registered_from'),
            $request->header('X-Registered-From'),
            $request->header('X-App-Platform'),
            $request->header('X-Client-OS'),
            $request->header('X-Platform'),
            $request->header('X-OS'),
        ];

        foreach ($candidates as $raw) {
            $n = $this->normalizePlatformCandidate($raw);
            if ($n !== null) {
                return $n;
            }
        }

        $data = $request->input('data');
        if (is_string($data) && $data !== '') {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($data)) {
            $data = [];
        }
        $fromData = $this->normalizePlatformCandidate(
            $data['platform'] ?? $data['device_platform'] ?? $data['deviceType'] ?? $data['os'] ?? null
        );
        if ($fromData !== null) {
            return $fromData;
        }

        $ua = (string) $request->userAgent();
        if (preg_match('/(iPhone|iPad|iPod|iOS|CFNetwork|CriOS)/i', $ua) === 1) {
            return User::REGISTERED_FROM_IOS;
        }
        if (stripos($ua, 'Android') !== false) {
            return User::REGISTERED_FROM_ANDROID;
        }

        return User::REGISTERED_FROM_WEB;
    }

    /**
     * On password / social login and email verify, keep registered_from in sync with the current client.
     */
    public function syncRegisteredFromOnLogin(User $user, Request $request): void
    {
        if ($user->isAdminRole()) {
            return;
        }

        $next = $this->resolveRegisteredFromRequest($request);
        if ((string) $user->registered_from === $next) {
            return;
        }

        $user->update(['registered_from' => $next]);
    }

    private function normalizePlatformCandidate(mixed $raw): ?string
    {
        $candidate = strtolower(trim((string) $raw));
        if ($candidate === '') {
            return null;
        }
        if (in_array($candidate, ['ios', 'iphone', 'ipados', 'ipad', 'ipod', 'apple'], true)) {
            return User::REGISTERED_FROM_IOS;
        }
        if (in_array($candidate, [User::REGISTERED_FROM_ANDROID, 'droid', 'aosp', 'fuchsia'], true)) {
            return User::REGISTERED_FROM_ANDROID;
        }
        if (in_array($candidate, [User::REGISTERED_FROM_WEB, 'browser', 'desktop', 'pwa', 'www'], true)) {
            return User::REGISTERED_FROM_WEB;
        }

        return null;
    }

    /**
     * Permanently delete user account and all associated data.
     *
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
        } catch (Exception $e) {
            DB::rollBack();
            throw new ApiOperationFailedException($e->getMessage(), 422);
        }
    }
}
