<?php

namespace App\Repositories;

use App\Exceptions\ApiOperationFailedException;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
     * @return array|JsonResponse
     */
    public function registerUser($request)
    {
        try {
            $data = $request->except('password');
            $data['password'] = Hash::make($request->password);

            if (@$data['name']) {
                $parts = explode(' ', trim($data['name']), 2);
                $data['first_name'] = $parts[0];
                $data['last_name'] = $parts[1] ?? null;
                unset($data['name']);
            }

            $otp = rand(100000, 999999);
            $data['otp'] = $otp;
            $data['otp_expires_at'] = now()->addMinutes(10);

            $user = $this->create($data);

            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\WelcomeMail($user, $request->password));
            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\VerifyEmailMail($user->email, $otp));

            return [
                'user' => $user,
                'message' => 'Registration successful. Please verify your email with the OTP sent.',
            ];
        } catch (\Exception $e) {
            throw new ApiOperationFailedException($e->getMessage(), 422);
        }
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

    /**
     * @param $provider
     * @param $socialUser
     *
     * @return User
     */
    public function handleSocialResponse($provider, $socialUser)
    {
        $user = User::where('provider', $provider)->where('provider_id', $socialUser->getId())->first();

        if (! $user) {
            $user = User::where('email', $socialUser->getEmail())->first();
            if ($user && $socialUser->getEmail()) {
                // Link account if email exists and matches
                $user->update([
                    'provider'    => $provider,
                    'provider_id' => $socialUser->getId(),
                ]);
            } else {
                // Check if name is provided, otherwise split email or use default
                $name = $socialUser->getName();
                $firstName = 'User';
                $lastName = null;
                if ($name) {
                    $parts = explode(' ', trim($name), 2);
                    $firstName = $parts[0];
                    $lastName = $parts[1] ?? null;
                }

                // Create new account
                $user = $this->create([
                    'first_name'  => $firstName,
                    'last_name'   => $lastName,
                    'email'       => $socialUser->getEmail(),
                    'provider'    => $provider,
                    'provider_id' => $socialUser->getId(),
                    'password'    => Hash::make(Str::random(24)),
                ]);
            }
        }

        return $user;
    }

    /**
     * @param $request
     *
     * @return JsonResponse
     */
    public function handleResetPassword($request): void
    {

        $reset = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$reset || !Hash::check($request->token, $reset->token)) {
            throw new ApiOperationFailedException('Invalid or expired password reset token.', 400);
        }

        if (\Carbon\Carbon::parse($reset->created_at)->addMinutes(30)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            throw new ApiOperationFailedException('Password reset token has expired.', 400);
        }

        $user = $this->findByEmail($request->email);
        if (!$user) {
            throw new ApiOperationFailedException('User not found.', 404);
        }

        $user->update(['password' => Hash::make($request->password)]);

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();
    }
    public function verifyOtp($email, $otp)
    {
        $user = $this->findByEmail($email);

        if (!$user) {
            throw new ApiOperationFailedException('User not found.', 404);
        }

        if ($user->email_verified_at) {
            throw new ApiOperationFailedException('Email already verified.', 400);
        }

        if ($user->otp !== $otp) {
            throw new ApiOperationFailedException('Invalid OTP.', 400);
        }

        if (now()->isAfter($user->otp_expires_at)) {
            throw new ApiOperationFailedException('OTP has expired.', 400);
        }

        $user->update([
            'email_verified_at' => now(),
            'otp' => null,
            'otp_expires_at' => null,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }

    public function resendOtp($email)
    {
        $user = $this->findByEmail($email);

        if (!$user) {
            throw new ApiOperationFailedException('User not found.', 404);
        }

        if ($user->email_verified_at) {
            throw new ApiOperationFailedException('Email already verified.', 400);
        }

        $otp = rand(100000, 999999);
        $user->update([
            'otp' => $otp,
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\VerifyEmailMail($user->email, $otp));

        return true;
    }

    public function deleteUserAccount(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            // Delete store (one-to-one) if exists
            if ($user->store) {
                // Media will be cleared by Spatie on delete
                $user->store->delete();
            }

            // Delete listings (one-to-many)
            // Need to iterate to ensure Spatie media is cleared for each listing
            foreach ($user->listings as $listing) {
                $listing->delete();
            }

            // Delete claims (one-to-many)
            foreach ($user->claims as $claim) {
                $claim->delete();
            }

            // Pivot table entries (favorites) are handled by Eloquent relationships 
            // but we can manually detach if needed. belongsToMany doesn't auto-delete pivot rows unless specified.
            $user->favoriteListings()->detach();

            // Delete all Sanctum tokens
            $user->tokens()->delete();

            // Finally delete the user (media will be cleared by Spatie)
            return $user->delete();
        });
    }
}
