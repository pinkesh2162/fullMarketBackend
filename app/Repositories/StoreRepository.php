<?php

namespace App\Repositories;

use App\Exceptions\ApiOperationFailedException;
use App\Jobs\SendFcmNotificationJob;
use App\Models\Listing;
use App\Models\Store;
use Illuminate\Container\Container as Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StoreRepository extends BaseRepository
{
    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    /**
     * @return array
     */
    public function getFieldsSearchable()
    {
        return [
            'name',
            'user_id',
        ];
    }

    /**
     * @return string
     */
    public function model()
    {
        return Store::class;
    }

    /**
     * Update or Create a store for the given user.
     *
     * @param  array  $data
     *
     * @throws ApiOperationFailedException
     */
    public function updateStore(int $userId, $request): Store
    {
        try {
            DB::beginTransaction();
            $data = $request->all();
            $store = $this->allQuery(['user_id' => $userId])->first();

            $data = [
                'name' => $data['name'] ?? null,
                'location' => $data['location'] ?? null,
                'business_time' => $data['business_time'] ?? null,
                'contact_information' => $data['contact_information'] ?? null,
                'social_media' => $data['social_media'] ?? null,
            ];
            if (! empty($store)) {
                $store = Store::updateOrCreate(
                    ['user_id' => $userId],
                    $data
                );
            } else {
                $data['user_id'] = $userId;

                $store = Store::create($data);

                $user = Auth::user();
                // Assign existing listings to the newly created/updated store
                Listing::where('user_id', $userId)
                    ->whereNull('store_id')
                    ->update(['store_id' => $store->id]);

                if ($user && $user->fcm_token) {
                    $title = 'Store Created';
                    $body = "Your store '{$store->name}' has been created successfully.";
                    dispatch_sync(new SendFcmNotificationJob($user->fcm_token, $title, $body, ['store_id' => $store->id], $user->id));
                }
            }

            // Handle cover_photo upload
            if (isset($request->cover_photo) && $request->cover_photo instanceof \Illuminate\Http\UploadedFile) {
                $store->clearMediaCollection(Store::COVER_PHOTO);
                $store->addMedia($request->cover_photo)->toMediaCollection(Store::COVER_PHOTO,
                    config('app.media_disc', 'public'));
            }

            if (isset($request->logo) && $request->logo instanceof \Illuminate\Http\UploadedFile) {
                $store->clearMediaCollection(Store::PROFILE_PHOTO);
                $store->addMedia($request->logo)->toMediaCollection(Store::PROFILE_PHOTO,
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
     * @throws ApiOperationFailedException
     */
    public function deleteStore(int $userId): bool
    {
        try {
            $store = $this->allQuery(['user_id' => $userId])->first();

            if (! $store) {
                throw new ApiOperationFailedException('store_not_found', 404);
            }

            // Preserve listings by setting store_id to null before deleting the store
            Listing::where('user_id', $userId)
                ->update(['store_id' => null]);

            // Spatie Media Library handles deleting attached media automatically
            // if configured (or we can just let CASCADE delete take care of it or manual clean up)
            $store->clearMediaCollection(Store::COVER_PHOTO);
            $store->clearMediaCollection(Store::PROFILE_PHOTO);

            return $store->delete();
        } catch (\Exception $e) {
            if ($e instanceof ApiOperationFailedException) {
                throw $e;
            }
            throw new ApiOperationFailedException('store_delete_failed', 500);
        }
    }
}
