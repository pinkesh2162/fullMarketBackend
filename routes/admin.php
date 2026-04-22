<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\AdminNotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['firebase.admin', 'throttle:admin-push'])
    ->post('admin/notifications/send', [AdminNotificationController::class, 'send']);

Route::middleware(['throttle:admin-dashboard'])
    ->get('/admin/dashboard', [DashboardController::class, 'index']);
