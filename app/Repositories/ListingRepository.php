<?php

namespace App\Repositories;

use App\Jobs\SendFcmNotificationJob;
use App\Models\Listing;
use App\Services\FcmService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ListingRepository
{
    public function getListings($filters = [], $perPage = 15): LengthAwarePaginator
    {
        $user = Auth::user();
        $hideAds = $filters['hide_ads'] ?? $user?->settings?->hide_ads ?? false;

        return Listing::with(['media'])
            ->when($hideAds, function ($query) {
                $query->where('service_type', '!=', Listing::OFFER_SERVICE);
            })
            ->filter($filters)
            ->latest()
            ->paginate($perPage);
    }

    public function getFeaturedListings($filters = [], $perPage = 3): LengthAwarePaginator
    {
        $user = Auth::user();
        $hideAds = $filters['hide_ads'] ?? $user?->settings?->hide_ads ?? false;

        return Listing::with(['media'])
            ->when($hideAds, function ($query) {
                $query->where('service_type', '!=', Listing::OFFER_SERVICE);
            })
            ->orderByDesc('views_count')
            ->filter($filters)
            ->latest()
            ->paginate($perPage);
    }

    public function getMyListings($filters = [], $perPage = 15): LengthAwarePaginator
    {
        return Listing::where('user_id', Auth::id())
            ->filter($filters)
            ->latest()
            ->paginate($perPage);
    }

    public function createListing(array $data, $images = null)
    {
        $user = Auth::user();
        $data['user_id'] = $user->id;
        $data['store_id'] = @$user->store ? $user->store->id : null;

        $listing = Listing::create($data);

        if ($images && is_array($images)) {
            foreach ($images as $image) {
                if ($image instanceof \Illuminate\Http\UploadedFile) {
                    $listing->addMedia($image)
                        ->toMediaCollection(Listing::LISTING_IMAGES, config('app.media_disc', 'public'));
                }
            }
        }

        // Send FCM notification

        if ($user && $user->fcm_token) {
            $title = "Listing Created";
            $body = "Your listing '{$listing->title}' has been created successfully.";
            dispatch_sync(new SendFcmNotificationJob($user->fcm_token, $title, $body, ['listing_id' => $listing->id], $user->id));
        }

        return $listing;
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
        $address = $listing->additional_info['location']['address'] ?? null;
        $keywords = array_filter(array_map('trim', explode(',', $listing->search_keyword ?? '')));

        return Listing::with(['user', 'store', 'category'])
            ->where(function ($query) use ($listing, $address, $keywords) {
                $query->where('service_category', $listing->service_category);

                if ($address) {
                    $query->orWhere('additional_info->location->address', 'like', "%{$address}%");
                }

                foreach ($keywords as $keyword) {
                    if ($keyword) {
                        $query->orWhere('search_keyword', 'like', "%{$keyword}%");
                    }
                }
            })
            ->where('id', '!=', $listing->id)
            ->latest()
            ->paginate($perPage);
    }
    public function incrementViews(Listing $listing): bool
    {
        return $listing->increment('views_count');
    }
}
