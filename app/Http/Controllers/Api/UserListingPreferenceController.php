<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\UserBlockedSeller;
use App\Models\UserBlockedStore;
use App\Models\UserHiddenListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserListingPreferenceController extends Controller
{
    /**
     * Hide a single listing from the authenticated user's feeds (idempotent).
     */
    public function hideListing(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'listing_id' => [
                'required',
                Rule::exists('listings', 'id')->whereNull('deleted_at'),
            ],
        ]);

        if ($validator->fails()) {
            return $this->preferenceValidationError($validator->errors()->first());
        }

        $user = $request->user();
        $listingId = (int) $request->input('listing_id');

        UserHiddenListing::query()->firstOrCreate(
            ['user_id' => $user->id, 'listing_id' => $listingId],
            []
        );

        return $this->actionSuccess('listing_hidden', ['success' => true]);
    }

    /**
     * Block all listings from a store (by store_id) or from an individual seller (by seller_user_id).
     * Send exactly one of: store_id, seller_user_id.
     */
    public function blockSeller(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')],
            'seller_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],
        ]);

        if ($validator->fails()) {
            return $this->preferenceValidationError($validator->errors()->first());
        }

        $hasStore = $request->filled('store_id');
        $hasSeller = $request->filled('seller_user_id');

        if ($hasStore && $hasSeller) {
            return $this->preferenceClientError(__('block_seller_single_target'));
        }

        if (! $hasStore && ! $hasSeller) {
            return $this->preferenceClientError(__('block_seller_target_required'));
        }

        $user = $request->user();

        if ($hasStore) {
            $store = Store::query()->findOrFail((int) $request->input('store_id'));
            if ((int) $store->user_id === (int) $user->id) {
                return $this->preferenceClientError(__('cannot_block_own_store'));
            }

            UserBlockedStore::query()->firstOrCreate(
                ['user_id' => $user->id, 'store_id' => $store->id],
                []
            );
        } else {
            $sellerId = (int) $request->input('seller_user_id');
            if ($sellerId === (int) $user->id) {
                return $this->preferenceClientError(__('cannot_block_yourself_seller'));
            }

            UserBlockedSeller::query()->firstOrCreate(
                ['user_id' => $user->id, 'blocked_user_id' => $sellerId],
                []
            );
        }

        return $this->actionSuccess('seller_blocked', ['success' => true]);
    }

    private function preferenceValidationError(string $firstMessage): JsonResponse
    {
        return $this->actionFailure('validation_failed', [
            'error_message' => $firstMessage,
            'error' => $firstMessage,
        ], self::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function preferenceClientError(string $message): JsonResponse
    {
        return $this->actionFailure('validation_failed', [
            'error_message' => $message,
            'error' => $message,
        ], self::HTTP_UNPROCESSABLE_ENTITY);
    }
}
