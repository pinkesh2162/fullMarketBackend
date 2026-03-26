<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiOperationFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserRegisterRequest;
use App\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\ForgotPasswordMail;

class AuthController extends Controller
{
    /**
     * @var UserRepository 
     */
    protected $userRepo;

    /**
     * AuthController constructor.
     * @param  UserRepository  $userRepository
     */
    public function __construct(UserRepository $userRepository)
    {
        $this->userRepo = $userRepository;
    }

    /**
     * @param  UserRegisterRequest  $request
     *
     * @throws ApiOperationFailedException
     * @return JsonResponse
     */
    public function register(UserRegisterRequest $request)
    {
        $data = $this->userRepo->registerUser($request);

        return $this->actionSuccess('User registered successfully', [
            'user' => $data['user'],
            'token' => $data['token'],
        ], self::HTTP_CREATED);
    }

    /**
     * @param  Request  $request
     * @return JsonResponse
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = $this->userRepo->findByEmail($request->email);

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->actionFailure('The provided credentials are incorrect.', null, self::HTTP_UNPROCESSABLE_ENTITY);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->actionSuccess('Logged in successfully', [
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * @param  Request  $request
     * @return JsonResponse
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->actionSuccess('Logged out successfully');
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = $this->userRepo->findByEmail($request->email);
        if (!$user) {
            return $this->notFound('Email not found.');
        }

        $token = \Illuminate\Support\Str::random(60);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $resetLink = env('FRONTEND_URL', 'http://localhost:3000') . '/reset-password?token=' . $token . '&email=' . $user->email;

        Mail::to($user->email)->send(new ForgotPasswordMail($user->email, $resetLink));

        return $this->actionSuccess('Password reset link sent to your email.');
    }

    /**
     * @param  Request  $request
     * @throws ApiOperationFailedException
     * @return JsonResponse
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $this->userRepo->handleResetPassword($request);

        return $this->actionSuccess('Password has been reset successfully.');
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return $this->actionFailure('Current password does not match.', null, self::HTTP_BAD_REQUEST);
        }

        $user->update(['password' => Hash::make($request->password)]);

        return $this->actionSuccess('Password changed successfully.');
    }
}
