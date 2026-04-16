<?php

use App\Http\Controllers\Api\Admin\AdminNotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['firebase.admin', 'throttle:admin-push'])
    ->post('admin/notifications/send', [AdminNotificationController::class, 'send']);
