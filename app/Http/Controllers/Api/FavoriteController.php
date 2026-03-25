<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Http\Resources\ListingResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FavoriteController extends Controller
{
    /**
     * Get user's favorite listings
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = @$request->perPage ?? 15;
        $favorites = $request->user()->favoriteListings()->latest('favorites.created_at')->paginate($perPage);
        
        return $this->actionSuccess('Favorites fetched successfully', ListingResource::collection($favorites));
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
        
        return $this->actionSuccess('Listing added to favorites successfully', null, self::HTTP_CREATED);
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
        
        return $this->actionSuccess('Listing removed from favorites successfully');
    }
}
