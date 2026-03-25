<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Repositories\StoreRepository;
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
    public function show(): JsonResponse
    {
        $store = Store::toBase()->where('user_id', auth()->id())->first();

        if (!$store) {
            return $this->notFound('Store not found');
        }

        return $this->actionSuccess('Store fetched successfully', new StoreResource($store));
    }

    /**
     * Update or create the authenticated user's store.
     */
    public function update(StoreRequest $request): JsonResponse
    {
        $store = $this->storeRepository->updateStore(auth()->id(), $request->validated());

        return $this->actionSuccess('Store updated successfully', new StoreResource($store));
    }

    /**
     * Delete the authenticated user's store.
     */
    public function destroy(): JsonResponse
    {
        $this->storeRepository->deleteStore(auth()->id());

        return $this->actionSuccess('Store deleted successfully');
    }
}
