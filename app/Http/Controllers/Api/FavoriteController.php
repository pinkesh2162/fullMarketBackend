<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    /**
     * Get user's favorite listings (excludes listings hidden / sellers blocked by the user).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = (int) ($request->input('perPage', $request->input('per_page', 15)));

        $listings = Listing::query()
            ->with(['user', 'store', 'category', 'media'])
            ->join('favorites', 'favorites.listing_id', '=', 'listings.id')
            ->where('favorites.user_id', $user->id)
            ->whereNull('favorites.deleted_at')
            ->visibleToUserId((int) $user->id)
            ->orderByDesc('favorites.created_at')
            ->select('listings.*')
            ->paginate($perPage);

        return $this->actionSuccess(
            'favorites_fetched',
            ListingResource::collection($listings),
            self::HTTP_OK,
            $this->customizingResponseData($listings)['pagination']
        );
    }

    /**
     * Add listing to favorites
     */
    public function store(Request $request, Listing $listing): JsonResponse
    {
        $user = $request->user();
        if (! $user->favoriteListings()->where('listing_id', $listing->id)->exists()) {
            $user->favoriteListings()->attach($listing->id);
        }

        return $this->actionSuccess('favorite_added', null, self::HTTP_CREATED);
    }

    /**
     * Remove listing from favorites
     */
    public function destroy(Request $request, Listing $listing): JsonResponse
    {
        $user = $request->user();
        if ($user->favoriteListings()->where('listing_id', $listing->id)->exists()) {
            $user->favoriteListings()->detach($listing->id);
        }

        return $this->actionSuccess('favorite_removed');
    }
}
