<?php

namespace App\Repositories;

use App\Models\Listing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class ListingRepository
{
    public function getListings($filters = [], $perPage = 15): LengthAwarePaginator
    {
        return Listing::with(['user', 'store', 'category'])
            ->filter($filters)
            ->latest()
            ->paginate($perPage);
    }

    public function getFeaturedListings($filters = [],$perPage = 3): LengthAwarePaginator
    {
        return Listing::with(['user', 'store', 'category'])
            ->orderByDesc('views_count')
             ->filter($filters)
            ->latest()
            ->paginate($perPage);
    }

    public function getMyListings($perPage = 15): LengthAwarePaginator
    {
        return Listing::where('user_id',Auth::id())->latest()->paginate($perPage);
    }

    public function createListing(array $data, $images = null): Bool
    {
        $listing = Listing::create($data);

        if ($images && is_array($images)) {
            foreach ($images as $image) {
                if ($image instanceof \Illuminate\Http\UploadedFile) {
                    $listing->addMedia($image)
                            ->toMediaCollection(Listing::LISTING_IMAGES, config('app.media_disc', 'public'));
                }
            }
        }

//        return $listing->load(['user', 'store', 'category']);
        return true;
    }

    public function getListingById(int $id): ?Listing
    {
        return Listing::with(['user', 'store', 'category'])->find($id);
    }

    public function updateListing(Listing $listing, array $data, $images = null): Listing
    {
        $listing->update($data);

        if ($images && is_array($images)) {
            foreach ($images as $image) {
                if ($image instanceof \Illuminate\Http\UploadedFile) {
                    $listing->addMedia($image)
                            ->toMediaCollection(Listing::LISTING_IMAGES, config('app.media_disc', 'public'));
                }
            }
        }

        return $listing->fresh(['user', 'store', 'category']);
    }

    public function deleteListing(Listing $listing): bool
    {
        return $listing->delete();
    }

    public function getRelatedListings(Listing $listing, $perPage = 6): LengthAwarePaginator
    {
        return Listing::with(['user', 'store', 'category'])
            ->where('service_category', $listing->service_category)
            ->where('id', '!=', $listing->id)
            ->latest()
            ->paginate($perPage);
    }
    public function incrementViews(Listing $listing): bool
    {
        return $listing->increment('views_count');
    }
}
