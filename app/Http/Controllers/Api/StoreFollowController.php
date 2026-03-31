<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendFcmNotificationJob;
use App\Models\Store;
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
            dispatch(new SendFcmNotificationJob($user->fcm_token, $title, $body));
        }
        
        return $this->actionSuccess('store_followed');
    }

    public function unfollow(Store $store): JsonResponse
    {
        auth()->user()->followedStores()->detach($store->id);
        return $this->actionSuccess('store_unfollowed');
    }
}
