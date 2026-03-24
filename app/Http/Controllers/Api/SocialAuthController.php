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
            return response()->json(['status' => false, 'message' => 'Invalid provider.'], 400);
        }

        try {
            if ($provider === 'apple') {
                $socialUser = Socialite::driver('apple')->stateless()->userFromToken($request->token);
            } else {
                $socialUser = Socialite::driver($provider)->stateless()->userFromToken($request->token);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => false, 'message' => 'Invalid or expired token.', 'error' => $e->getMessage(),
            ], 401);
        }

        $user = $this->userRepo->handleSocialResponse($provider, $socialUser);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully',
            'user'    => $user,
            'token'   => $token,
        ]);
    }
}
