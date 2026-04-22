<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Api\Admin\AdminNotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['firebase.admin', 'throttle:admin-push'])
    ->post('admin/notifications/send', [AdminNotificationController::class, 'send']);

Route::middleware(['throttle:admin-dashboard'])
    ->get('admin/dashboard', [DashboardController::class, 'index']);

Route::middleware(['throttle:admin-api'])->prefix('admin')
    ->group(function () {
        Route::group(['prefix' => '/users'], function () {
            Route::get('/countries', [UserController::class, 'countries']);
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::get('/{id}', [UserController::class, 'show'])->whereNumber('id');
            // Route::patch('/{id}', [UserController::class, 'update'])->whereNumber('id');
            Route::post('/{id}', [UserController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [UserController::class, 'destroy'])->whereNumber('id');
        });
    });
