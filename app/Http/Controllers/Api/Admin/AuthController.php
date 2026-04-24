<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = User::with('media')->where('email', $request->email)->first();
        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->actionFailure('invalid_credentials', null, self::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $user->isAdminRole()) {
            return $this->actionFailure('admin_only', null, self::HTTP_FORBIDDEN);
        }

        if (! $user->allowsAppLogin()) {
            return $this->actionFailure('account_disabled', null, self::HTTP_FORBIDDEN);
        }

        if (! $user->email_verified_at) {
            return $this->actionFailure('otp_not_verified', null, self::HTTP_FORBIDDEN);
        }

        try {
            $user->loadMissing(['media', 'store', 'stores']);
            $token = $user->createToken('admin_token')->plainTextToken;

            return $this->actionSuccess('admin_login_success', [
                'user' => UserResource::make($user),
                'token' => $token,
                'token_type' => 'Bearer',
                'role' => User::ROLE_ADMIN,
            ]);
        } catch (\Throwable $e) {
            return $this->actionFailure($e->getMessage(), null, self::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return $this->actionSuccess('admin_logout_success');
    }
}
