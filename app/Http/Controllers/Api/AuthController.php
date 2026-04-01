<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiOperationFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserRegisterRequest;
use App\Http\Resources\UserResource;
use App\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\ForgotPasswordMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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
     * @param  Request  $request
     * @return JsonResponse
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $user = Cache::remember("user_{$request->email}", 60, function () use ($request) {
            return User::with('media')
                ->where('email', $request->email)
                ->first();
        });

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->actionFailure('invalid_credentials', null, self::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$user->email_verified_at) {
            return $this->actionFailure('otp_not_verified', null, 403);
        }

        try {
            $token = $user->createToken('auth_token')->plainTextToken;

            return $this->actionSuccess('login_success', [
                'user' => UserResource::make($user),
                'token' => $token,
            ]);
        } catch (\Exception $e) {
            return $this->actionFailure($e->getMessage(), null, self::HTTP_INTERNAL_SERVER_ERROR);
        }
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

        return $this->actionSuccess($data['message'], [
            'user' => $data['user'],
        ], self::HTTP_CREATED);
    }

    /**
     * @param  Request  $request
     * @throws ApiOperationFailedException
     * @return JsonResponse
     */
    public function verifyEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6',
        ]);

        $data = $this->userRepo->verifyOtp($request->email, $request->otp);

        return $this->actionSuccess('email_verified', $data);
    }

    /**
     * @param  Request  $request
     * @throws ApiOperationFailedException
     * @return JsonResponse
     */
    public function resendOtp(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $this->userRepo->resendOtp($request->email);

        return $this->actionSuccess('otp_resent');
    }

    /**
     * @param  Request  $request
     * @return JsonResponse
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->actionSuccess('logout_success');
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
            return $this->notFound('email_not_found');
        }

        $token = Str::random(60);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $resetLink = env('FRONTEND_URL', 'http://localhost:3000') . '/reset-password?token=' . $token . '&email=' . $user->email;

        Mail::to($user->email)->queue(new ForgotPasswordMail($user->email, $resetLink));

        return $this->actionSuccess('password_reset_link_sent');
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

        return $this->actionSuccess('password_reset_success');
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
            return $this->actionFailure('current_password_invalid', null, self::HTTP_BAD_REQUEST);
        }

        $user->update(['password' => Hash::make($request->password)]);

        return $this->actionSuccess('password_changed_success');
    }
}
