<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ListingController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\StoreFollowController;
use App\Http\Controllers\Api\StoreRatingController;

use App\Http\Controllers\Api\ContactController;

//guest routes
Route::post('/contact', [ContactController::class, 'send']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
// Route::get('/auth/google/redirect', [SocialAuthController::class, 'redirectToGoogle']);
Route::post('/auth/{provider}/callback', [SocialAuthController::class, 'handleProviderCallback']);
Route::get('/main-categories', [CategoryController::class, 'getMainCategories']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/listings', [ListingController::class, 'index']);
Route::get('/featured-listings', [ListingController::class, 'getFeaturedListings']);
Route::get('/listings/{listing}/related', [ListingController::class, 'getRelatedListings']);
Route::post('/claim-add', [ClaimController::class, 'store']);


Route::middleware(['auth:sanctum'])->group(function () {
    //    user profile route
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/get-profile', [ProfileController::class, 'getProfile']);
    Route::post('/edit-profile', [ProfileController::class, 'updateProfile']);
    Route::post('/delete-account', [ProfileController::class, 'deleteAccount']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    // category routes
    Route::post('/categories', [CategoryController::class, 'store']);

    // listing routes
    Route::apiResource('listings', ListingController::class);
    Route::get('/my-listings', [ListingController::class, 'getMyListing']);

    // favorites routes
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/listings/{listing}/favorite', [FavoriteController::class, 'store']);
    Route::delete('/listings/{listing}/favorite', [FavoriteController::class, 'destroy']);

    // user store routes
    Route::get('/store', [StoreController::class, 'show']);
    Route::post('/edit-store', [StoreController::class, 'update']);
    Route::post('/delete-store', [StoreController::class, 'destroy']);

    // Store Follow and Rating routes
    Route::post('/stores/{store}/follow', [StoreFollowController::class, 'follow']);
    Route::post('/stores/{store}/unfollow', [StoreFollowController::class, 'unfollow']);
    Route::post('/stores/{store}/rate', [StoreRatingController::class, 'rate']);
});
