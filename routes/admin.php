<?php

use App\Http\Controllers\Api\Admin\AdminNotificationController;
use App\Http\Controllers\Api\Admin\AuthController;
use App\Http\Controllers\Api\Admin\ClaimController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\ListingController;
use App\Http\Controllers\Api\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['firebase.admin', 'throttle:admin-push'])
    ->post('admin/notifications/send', [AdminNotificationController::class, 'send']);

Route::post('/admin/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);

        Route::get('dashboard', [DashboardController::class, 'index']);

        Route::prefix('users')->group(function () {
            Route::get('countries', [UserController::class, 'countries']);
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::get('{id}', [UserController::class, 'show'])->whereNumber('id');
            Route::post('{id}', [UserController::class, 'update'])->whereNumber('id');
            Route::delete('{id}', [UserController::class, 'destroy'])->whereNumber('id');
        });

        Route::get('listings/summary', [ListingController::class, 'summary']);
        Route::get('listings', [ListingController::class, 'index']);
        Route::get('listings/{id}', [ListingController::class, 'show'])->whereNumber('id');
        Route::patch('listings/{id}/feature', [ListingController::class, 'feature'])->whereNumber('id');
        Route::put('listings/{id}/feature', [ListingController::class, 'feature'])->whereNumber('id');
        Route::delete('listings/{id}', [ListingController::class, 'destroy'])->whereNumber('id');

        Route::get('claims/summary', [ClaimController::class, 'summary']);
        Route::get('claims', [ClaimController::class, 'index']);
        Route::get('claims/{id}', [ClaimController::class, 'show'])->whereNumber('id');
        // Route::patch('claims/{id}/status', [ClaimController::class, 'changeStatus'])->whereNumber('id');
        Route::post('claims/{id}/status', [ClaimController::class, 'changeStatus'])->whereNumber('id');
        Route::delete('claims/{id}', [ClaimController::class, 'destroy'])->whereNumber('id');

        Route::get('notifications/audience', [AdminNotificationController::class, 'audience']);
        Route::get('notifications/filters', [AdminNotificationController::class, 'filters']);
        Route::post('notifications/panel-send', [AdminNotificationController::class, 'sendNotification']);
    });
