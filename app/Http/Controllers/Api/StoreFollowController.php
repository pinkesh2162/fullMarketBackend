<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreFollowController extends Controller
{
    public function follow(Store $store): JsonResponse
    {
        auth()->user()->followedStores()->syncWithoutDetaching([$store->id]);
        return $this->actionSuccess('store_followed');
    }

    public function unfollow(Store $store): JsonResponse
    {
        auth()->user()->followedStores()->detach($store->id);
        return $this->actionSuccess('store_unfollowed');
    }
}
