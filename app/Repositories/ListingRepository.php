<?php

namespace App\Repositories;

use App\Jobs\SendFcmNotificationJob;
use App\Models\Favorite;
use App\Models\Listing;
use App\Models\SearchSuggestion;
use Exception;
use Illuminate\Container\Container as Application;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ListingRepository extends BaseRepository
{
    /**
     * @param  Application  $app
     * @throws Exception
     */
    public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    /**
     * @return array
     */
    public function getFieldsSearchable()
    {
        return Listing::FILTER_PARAMS;
    }

    /**
     * @return string
     */
    public function model()
    {
        return Listing::class;
    }

    /**
     * Records search terms for suggestions.
     *
     * @param  string|null  $term
     */
    public function recordSearchTerm(?string $term): void
    {
        if (empty($term)) {
            return;
        }

        $term = strtolower(trim($term));
        if (strlen($term) < 2) {
            return;
        }

        // Using updateOrCreate with DB::raw to avoid race conditions and set default
        SearchSuggestion::updateOrCreate(
            ['term' => $term],
            ['hits' => DB::raw('hits + 1')]
        );
    }

    /**
     * @param  array  $filters
     * @param  int  $perPage
     *
     * @return LengthAwarePaginator
     */
    public function getListings($filters = [], $perPage = 15): LengthAwarePaginator
    {
        $version = Cache::get('listing_cache_version', 1);
        $user = Auth::user();
        $userId = $user?->id ?? 'guest';
        $filtersHash = md5(serialize($filters));
        $cacheKey = "listings_v{$version}_{$userId}_{$perPage}_{$filtersHash}";

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($filters, $perPage, $user) {
            $hideAds = $filters['hide_ads'] ?? $user?->settings?->hide_ads ?? false;

            $query = $this->allQuery()->with(['media'])->filter($filters);

            if ($hideAds) {
                $query->where('service_type', '!=', Listing::OFFER_SERVICE);
            }

            $listings = $query->latest()->paginate($perPage);

            if (!empty($filters['title']) || !empty($filters['search_keyword'])) {
                $this->recordSearchTerm($filters['title'] ?? $filters['search_keyword']);
            }

            return $listings;
        });
    }

    /**
     * @param  array  $filters
     * @param  int  $perPage
     *
     * @return LengthAwarePaginator
     */
    public function getFeaturedListings($filters = [], $perPage = 3): LengthAwarePaginator
    {
        $version = Cache::get('listing_cache_version', 1);
        $user = Auth::user();
        $userId = $user?->id ?? 'guest';
        $filtersHash = md5(serialize($filters));
        $cacheKey = "featured_listings_v{$version}_{$userId}_{$perPage}_{$filtersHash}";

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($filters, $perPage, $user) {
            $hideAds = $filters['hide_ads'] ?? $user?->settings?->hide_ads ?? false;

            $query = $this->allQuery()->with(['media'])->filter($filters);

            if ($hideAds) {
                $query->where('service_type', '!=', Listing::OFFER_SERVICE);
            }

            $listings = $query->orderByDesc('views_count')
                ->latest()
                ->paginate($perPage);

            if (!empty($filters['search_keyword'])) {
                $this->recordSearchTerm($filters['search_keyword']);
            }

            return $listings;
        });
    }

    /**
     * @param  array  $filters
     * @param  int  $perPage
     *
     * @return LengthAwarePaginator
     */
    public function getMyListings($filters = [], $perPage = 15): LengthAwarePaginator
    {
        $version = Cache::get('listing_cache_version', 1);
        $userId = Auth::id();
        $filtersHash = md5(serialize($filters));
        $cacheKey = "my_listings_v{$version}_{$userId}_{$perPage}_{$filtersHash}";

        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($filters, $perPage, $userId) {
            return $this->allQuery()->where('user_id', $userId)
                ->filter($filters)
                ->latest()
                ->paginate($perPage);
        });
    }

    /**
     * @param  array  $data
     * @param  null  $images
     *
     * @return mixed
     */
    public function createListing(array $data, $images = null)
    {
        $user = Auth::user();
        $data['user_id'] = $user->id;
        $data['store_id'] = @$user->store ? $user->store->id : null;

        $listing = $this->create($data);

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

        $this->clearListingCache();

        return $listing;
    }

    /**
     * @param  Listing  $listing
     * @param  array  $data
     * @param  null  $images
     *
     * @return Builder|Builder[]|Collection|Model
     */
    public function updateListing(Listing $listing, array $data, $images = null)
    {
        $listing = $this->update($data, $listing->id);

        if ($images && is_array($images)) {
            foreach ($images as $image) {
                if ($image instanceof \Illuminate\Http\UploadedFile) {
                    $listing->addMedia($image)
                        ->toMediaCollection(Listing::LISTING_IMAGES, config('app.media_disc', 'public'));
                }
            }
        }

        $this->clearListingCache();

        return $listing->fresh(['user', 'store', 'category']);
    }

    /**
     * @param  Listing  $listing
     * @throws Exception
     * @return bool
     */
    public function deleteListing(Listing $listing): bool
    {
        $deleted = $this->delete($listing->id);
        if ($deleted) {
            $this->clearListingCache();
        }
        return $deleted;
    }

    /**
     * Clears all listing related caches.
     */
    public function clearListingCache(): void
    {
        // Since we are using structured keys without tags (default file driver),
        // we might not be able to clear all variations easily without tags.
        // However, we can use a cache version or similar strategy.
        // For now, if Redis is available, tags are better.
        // If not, we'll use a 'listings_updated_at' timestamp in keys.

        Cache::forget('listings_version');
        // Actually, a simpler way for file cache is to just flush or use a version prefix.
        // Let's use a versioning approach to effectively invalidate everything.
        Cache::increment('listing_cache_version');
    }

    /**
     * @param  Listing  $listing
     * @param  int  $perPage
     *
     * @return LengthAwarePaginator
     */
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

    /**
     * @param  Listing  $listing
     *
     * @return bool
     */
    public function incrementViews(Listing $listing): bool
    {
        return $listing->increment('views_count');
    }

    /**
     * @return array
     */
    public function getCount(){
        $listingCount = Listing::toBase()
            ->where('user_id', auth('sanctum')->id())
            ->count();
        $favoriteCount = Favorite::toBase()
            ->where('user_id', auth('sanctum')->id())
            ->count();

        return [
            'listing_count' => $listingCount,
            'favorite_count' => $favoriteCount,
        ];
    }
}
