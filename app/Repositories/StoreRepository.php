<?php

namespace App\Repositories;

use App\Exceptions\ApiOperationFailedException;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

class StoreRepository
{
    /**
     * Update or Create a store for the given user.
     *
     * @param int $userId
     * @param array $data
     * @return Store
     * @throws ApiOperationFailedException
     */
    public function updateStore(int $userId, array $data): Store
    {
        try {
            DB::beginTransaction();

            $store = Store::updateOrCreate(
                ['user_id' => $userId],
                [
                    'name' => $data['name'] ?? null,
                    'location' => $data['location'] ?? null,
                    'business_time' => $data['business_time'] ?? null,
                    'contact_information' => $data['contact_information'] ?? null,
                    'social_media' => $data['social_media'] ?? null,
                ]
            );

            // Handle cover_photo upload
            if (isset($data['cover_photo']) && $data['cover_photo'] instanceof \Illuminate\Http\UploadedFile) {
                $store->clearMediaCollection(Store::COVER_PHOTO);
                $store->addMedia($data['cover_photo'])->toMediaCollection(Store::COVER_PHOTO,
                    config('app.media_disc', 'public'));
            }

             if (isset($data['logo']) && $data['logo'] instanceof \Illuminate\Http\UploadedFile) {
                $store->clearMediaCollection(Store::PROFILE_PHOTO);
                $store->addMedia($data['logo'])->toMediaCollection(Store::PROFILE_PHOTO,
                    config('app.media_disc', 'public'));
            }

            DB::commit();

            return $store;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ApiOperationFailedException($e->getMessage(), 500);
        }
    }

    /**
     * Delete the store for the given user.
     *
     * @param int $userId
     * @return bool
     * @throws ApiOperationFailedException
     */
    public function deleteStore(int $userId): bool
    {
        try {
            $store = Store::where('user_id', $userId)->first();
            
            if (!$store) {
                throw new ApiOperationFailedException('Store not found', 404);
            }

            // Spatie Media Library handles deleting attached media automatically 
            // if configured (or we can just let CASCADE delete take care of it or manual clean up)
            $store->clearMediaCollection('cover_photo');
            $store->clearMediaCollection('logo');
            
            return $store->delete();
        } catch (\Exception $e) {
            if ($e instanceof ApiOperationFailedException) {
                throw $e;
            }
            throw new ApiOperationFailedException('Failed to delete store', 500);
        }
    }
}
