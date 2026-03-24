<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRegisterRequest;
use App\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * @var UserRepository 
     */
    protected $userRepository;

    /**
     * AuthController constructor.
     * @param  UserRepository  $userRepository
     */
    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * @param  UserRegisterRequest  $request
     *
     * @return JsonResponse
     */
    public function register(UserRegisterRequest $request)
    {
        $data = $request->except('password');
        $data['password'] = Hash::make($request->password);

        if(@$data['name']){
            $parts = explode(' ', trim($data['name']), 2);
            $data['first_name'] = $parts[0];
            $data['last_name'] = $parts[1] ?? null;
            unset($data['name']);
        }
        
        $user = $this->userRepository->create($data);
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user,
            'token' => $token,
        ], 201);
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

        $user = $this->userRepository->findByEmail($request->email);

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json('The provided credentials are incorrect.', 422);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully',
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

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }
}
