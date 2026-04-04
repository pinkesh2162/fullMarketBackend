<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Repositories\StoreRepository;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class StoreController extends Controller
{
    protected StoreRepository $storeRepository;

    public function __construct(StoreRepository $storeRepository)
    {
        $this->storeRepository = $storeRepository;
    }

    /**
     * Display the authenticated user's store.
     */
    public function show(Request $request): JsonResponse
    {
        $store = Store::with('media')
            ->when($request->id, function ($query) use ($request) {
                $query->where('id', $request->id);
            }, function ($query) {
                $query->where('user_id', auth('sanctum')->id());
            })
            ->first();

        if (!$store) {
            return $this->notFound('store_not_found');
        }

        return $this->actionSuccess('store_fetched', new StoreResource($store));
    }

    /**
     * Update or create the authenticated user's store.
     */
    public function update(StoreRequest $request): JsonResponse
    {
        $store = $this->storeRepository->updateStore(auth()->id(), $request);

        return $this->actionSuccess('store_updated', new StoreResource($store));
    }

    /**
     * Delete the authenticated user's store.
     */
    public function destroy(): JsonResponse
    {
        $this->storeRepository->deleteStore(auth()->id());

        return $this->actionSuccess('store_deleted');
    }
}
