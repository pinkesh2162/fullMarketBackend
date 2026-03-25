<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\CategoryController;

//guest routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
// Route::get('/auth/google/redirect', [SocialAuthController::class, 'redirectToGoogle']);
Route::post('/auth/{provider}/callback', [SocialAuthController::class, 'handleProviderCallback']);
Route::get('/categories', [CategoryController::class, 'index']);


Route::middleware(['auth:sanctum'])->group(function () {
//    user profile route
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/get-profile', [ProfileController::class, 'getProfile']);
    Route::post('/edit-profile', [ProfileController::class, 'updateProfile']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // category routes
    Route::post('/categories', [CategoryController::class, 'store']);

    // user store routes
    Route::get('/store', [StoreController::class, 'show']);
    Route::post('/edit-store', [StoreController::class, 'update']);
    Route::post('/delete-store', [StoreController::class, 'destroy']);
});
