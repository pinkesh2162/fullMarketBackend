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
use App\Http\Controllers\Api\NotificationController;

use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\AppSettingController;

//guest routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/app-social-login', [SocialAuthController::class, 'handleAppSocialLogin']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

Route::post('/contact', [ContactController::class, 'send']);
Route::get('/main-categories', [CategoryController::class, 'getMainCategories']);
Route::get('/categories', [CategoryController::class, 'index']);

Route::get('/listings', [ListingController::class, 'index']);
Route::get('/featured-listings', [ListingController::class, 'getFeaturedListings']);
Route::get('/listings/{listing}/related', [ListingController::class, 'getRelatedListings']);
Route::post('/claim-add', [ClaimController::class, 'store']);
Route::get('/app-settings', [AppSettingController::class, 'getAppSettings']);
Route::get('get/listing/{id}', [ListingController::class, 'show']);
Route::get('/store', [StoreController::class, 'show']);

Route::middleware(['auth:sanctum'])->group(function () {
    //    user profile route
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/get-profile', [ProfileController::class, 'getProfile']);
    Route::post('/edit-profile', [ProfileController::class, 'updateProfile']);
    Route::post('/update-fcm-token', [ProfileController::class, 'updateFcmToken']);
    Route::post('/delete-account', [ProfileController::class, 'deleteAccount']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::get('/get-settings', [ProfileController::class, 'getSettings']);
    Route::post('/update-settings', [ProfileController::class, 'updateSettings']);
    Route::post('/app-settings', [AppSettingController::class, 'updateAppSettings']);
    Route::get('/get-counts', [ListingController::class, 'getCount']);

    // notifications routes
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications-delete-assign', [NotificationController::class, 'destroyAssign']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications-delete-all', [NotificationController::class, 'deleteAll']);

    // category routes
    Route::post('/categories', [CategoryController::class, 'store']);

    // listing routes
    // Route::apiResource('listings', ListingController::class);
    Route::post('listings', [ListingController::class, 'store']);
    Route::delete('listings/{id}', [ListingController::class, 'destroy']);
    Route::post('update/listing/{id}', [ListingController::class, 'update']);
    Route::get('/my-listings', [ListingController::class, 'getMyListing']);

    // favorites routes
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/listings/{listing}/favorite', [FavoriteController::class, 'store']);
    Route::delete('/listings/{listing}/favorite', [FavoriteController::class, 'destroy']);

    // user store routes
    Route::post('/edit-store', [StoreController::class, 'update']);
    Route::post('/delete-store', [StoreController::class, 'destroy']);

    // Store Follow and Rating routes
    Route::post('/stores/{store}/follow', [StoreFollowController::class, 'follow']);
    Route::post('/stores/{store}/unfollow', [StoreFollowController::class, 'unfollow']);
    Route::post('/stores/{store}/rate', [StoreRatingController::class, 'rate']);
});
