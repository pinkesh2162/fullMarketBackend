<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SocialAuthController;

//guest routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
// Route::get('/auth/google/redirect', [SocialAuthController::class, 'redirectToGoogle']);
Route::post('/auth/{provider}/callback', [SocialAuthController::class, 'handleProviderCallback']);


Route::middleware(['auth:sanctum'])->group(function () {
//    user profile route
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/get-profile', [ProfileController::class, 'getProfile']);
    Route::post('/edit-profile', [ProfileController::class, 'updateProfile']);
});
