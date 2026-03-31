<?php

namespace App\Repositories;

use App\Exceptions\ApiOperationFailedException;
use App\Models\Listing;
use App\Models\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StoreRepository
{
    /**
     * Update or Create a store for the given user.
     *
     * @param  int  $userId
     * @param  array  $data
     * @throws ApiOperationFailedException
     * @return Store
     */
    public function updateStore(int $userId, array $data): Store
    {
        try {
            DB::beginTransaction();

            $store = Store::where('user_id', $userId)->first();

            $data = [
                'name' => $data['name'] ?? null,
                'location' => $data['location'] ?? null,
                'business_time' => $data['business_time'] ?? null,
                'contact_information' => $data['contact_information'] ?? null,
                'social_media' => $data['social_media'] ?? null,
            ];
            if(!empty($store)){
                $store = Store::updateOrCreate(
                ['user_id' => $userId],
                $data
            );
            }else{
                $data['user_id'] = $userId;

                $store = Store::create($data);

                 $user = Auth::user();
                // Assign existing listings to the newly created/updated store
                Listing::where('user_id', $userId)
                    ->whereNull('store_id')
                    ->update(['store_id' => $store->id]);

                if ($user && $user->fcm_token) {
                    $title = "Store Created";
                    $body = "Your store '{$store->name}' has been created successfully.";
                    dispatch(new \App\Jobs\SendFcmNotificationJob($user->fcm_token, $title, $body));
                }
            }


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
                throw new ApiOperationFailedException('store_not_found', 404);
            }

            // Preserve listings by setting store_id to null before deleting the store
            Listing::where('user_id', $userId)
                ->update(['store_id' => null]);

            // Spatie Media Library handles deleting attached media automatically
            // if configured (or we can just let CASCADE delete take care of it or manual clean up)
            $store->clearMediaCollection('cover_photo');
            $store->clearMediaCollection('logo');

            return $store->delete();
        } catch (\Exception $e) {
            if ($e instanceof ApiOperationFailedException) {
                throw $e;
            }
            throw new ApiOperationFailedException('store_delete_failed', 500);
        }
    }
}
