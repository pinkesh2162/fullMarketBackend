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
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Store fetched successfully',
            'data' => new StoreResource($store),
        ]);
    }

    /**
     * Update or create the authenticated user's store.
     */
    public function update(StoreRequest $request): JsonResponse
    {
        $store = $this->storeRepository->updateStore(auth()->id(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Store updated successfully',
            'data' => new StoreResource($store),
        ]);
    }

    /**
     * Delete the authenticated user's store.
     */
    public function destroy(): JsonResponse
    {
        $this->storeRepository->deleteStore(auth()->id());

        return response()->json([
            'success' => true,
            'message' => 'Store deleted successfully',
        ]);
    }
}
