<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use App\Repositories\UserRepository;

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

        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->actionSuccess('login_success', [
            'user' => \App\Http\Resources\UserResource::make($user),
            'token' => $token,
        ]);
    }

    /**
     * Authenticate or register a user with a given provider token.
     *
     * @param  Request  $request
     * @param  string  $provider
     * @return JsonResponse
     */
    public function handleProviderCallback(Request $request, $provider)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        if (! in_array($provider, ['google', 'apple'])) {
            return $this->actionFailure('invalid_provider', null, self::HTTP_BAD_REQUEST);
        }

        try {
            if ($provider === 'apple') {
                $socialUser = Socialite::driver('apple')->stateless()->userFromToken($request->token);
            } else {
                $socialUser = Socialite::driver($provider)->stateless()->userFromToken($request->token);
            }
        } catch (\Exception $e) {
            return $this->actionFailure('invalid_token', ['error' => $e->getMessage()], self::HTTP_UNAUTHORIZED);
        }

        $user = $this->userRepo->handleSocialResponse($provider, $socialUser);

        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->actionSuccess('login_success', [
            'user'    => $user,
            'token'   => $token,
        ]);
    }
}
