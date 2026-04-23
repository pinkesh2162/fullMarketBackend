<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Repositories\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SocialAuthController extends Controller
{
    protected $userRepo;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepo = $userRepository;
    }

    public function handleAppSocialLogin(Request $request)
    {
        $request->validate([
            'connection' => 'required|string',
            'userInfo' => 'required|array',
            'userInfo.sub' => 'required|string',
            'userInfo.email' => 'required|email',
        ]);

        $provider = Str::before($request->connection, '-'); // e.g. "google-oauth2" -> "google"

        $user = $this->userRepo->handleAppSocialLogin($provider, $request->all());

        if ($user->isAdminRole()) {
            return $this->actionFailure('admin_login_required', null, self::HTTP_FORBIDDEN);
        }

        if (! $user->allowsAppLogin()) {
            return $this->actionFailure('account_disabled', null, self::HTTP_FORBIDDEN);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->actionSuccess('login_success', [
            'user' => UserResource::make($user),
            'token' => $token,
        ]);
    }
}
