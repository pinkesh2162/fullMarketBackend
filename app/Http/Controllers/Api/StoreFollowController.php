<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendFcmNotificationJob;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StoreFollowController extends Controller
{
    public function follow(Store $store): JsonResponse
    {
        auth()->user()->followedStores()->syncWithoutDetaching([$store->id]);
        $user = Auth::user();

        if ($user && $user->fcm_token) {
            $title = "Store Followed";
            $body = "You have followed the store '{$store->name}'.";
            dispatch_sync(new SendFcmNotificationJob($user->fcm_token, $title, $body, ['store_id' => $store->id], $user->id));
        }

        return $this->actionSuccess('store_followed');
    }

    public function unfollow(Store $store): JsonResponse
    {
        auth()->user()->followedStores()->detach($store->id);
        return $this->actionSuccess('store_unfollowed');
    }

    public function sendNotificationTest(Request $request): JsonResponse
    {
        $user = User::findOrFail((int)$request->id);
        if ($user && $user->fcm_token) {
            $title = $request->title ?? "Test Notification";
            $body = $request->body ?? "This is a test notification.";
            dispatch_sync(new SendFcmNotificationJob($user->fcm_token, $title, $body, ['store_id' => 1], $user->id));
        }
        return $this->actionSuccess('notification_sent');
    }
}
