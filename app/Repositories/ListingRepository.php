<?php

namespace App\Repositories;

use App\Jobs\SendFcmNotificationJob;
use App\Models\Favorite;
use App\Models\Listing;
use App\Models\SearchSuggestion;
use Exception;
use Illuminate\Container\Container as Application;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    public function getListings($filters = [], $perPage = 15)
    {
//        $version = Cache::get('listing_cache_version', 1);
        $user = Auth::user();
//        $userId = $user?->id ?? 'guest';
//        $filtersHash = md5(serialize($filters));
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
//        $cacheKey = "listings_v{$version}_{$userId}_{$perPage}_{$filtersHash}";

//        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($filters, $perPage, $user) {
//            $hideAds = $filters['hide_ads'] ?? $user?->settings?->hide_ads ?? false;
//
//            $query = $this->allQuery()->with(['media'])->filter($filters);
//
//            if ($hideAds) {
//                $query->where('service_type', '!=', Listing::OFFER_SERVICE);
//            }
//
//            $listings = $query->latest()->paginate($perPage);
//
//            if (!empty($filters['title']) || !empty($filters['search_keyword'])) {
//                $this->recordSearchTerm($filters['title'] ?? $filters['search_keyword']);
//            }
//
//            return $listings;
//        });
    }

    /**
     * @param  array  $filters
     * @param  int  $perPage
     *
     * @return LengthAwarePaginator
     */
    public function getFeaturedListings($filters = [], $perPage = 3)
    {
//        $version = Cache::get('listing_cache_version', 1);
        $user = Auth::user();
//        $userId = $user?->id ?? 'guest';
//        $filtersHash = md5(serialize($filters));
//        $cacheKey = "featured_listings_v{$version}_{$userId}_{$perPage}_{$filtersHash}";

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
//        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($filters, $perPage, $user) {
//            $hideAds = $filters['hide_ads'] ?? $user?->settings?->hide_ads ?? false;
//
//            $query = $this->allQuery()->with(['media'])->filter($filters);
//
//            if ($hideAds) {
//                $query->where('service_type', '!=', Listing::OFFER_SERVICE);
//            }
//
//            $listings = $query->orderByDesc('views_count')
//                ->latest()
//                ->paginate($perPage);
//
//            if (!empty($filters['search_keyword'])) {
//                $this->recordSearchTerm($filters['search_keyword']);
//            }
//
//            return $listings;
//        });
    }

    /**
     * @param  array  $filters
     * @param  int  $perPage
     *
     * @return LengthAwarePaginator
     */
    public function getMyListings($filters = [], $perPage = 15)
    {
//        $version = Cache::get('listing_cache_version', 1);
        $userId = Auth::id();
//        $filtersHash = md5(serialize($filters));
//        $cacheKey = "my_listings_v{$version}_{$userId}_{$perPage}_{$filtersHash}";

        return $this->allQuery()->where('user_id', $userId)
            ->filter($filters)
            ->latest()
            ->paginate($perPage);

//        return Cache::remember($cacheKey, now()->addMinutes(30), function () use ($filters, $perPage, $userId) {
//            return $this->allQuery()->where('user_id', $userId)
//                ->filter($filters)
//                ->latest()
//                ->paginate($perPage);
//        });
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

//        $this->clearListingCache();

        return $listing;
    }

    /**
     * @param  Listing  $listing
     * @param  array  $data
     * @param  null  $images
     *
     * @return Builder|Builder[]|\Illuminate\Database\Eloquent\Collection|Model
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

//        $this->clearListingCache();

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
//        if ($deleted) {
//            $this->clearListingCache();
//        }
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
     * Related listings: same behavior as mobile Firebase getRelatedListings (category-first pool,
     * location/keywords as ranking boosts, geo merge, fallback when sparse).
     *
     * @param  int  $perPage  Page size (also reads as `limit` from API)
     * @param  int|null  $page  1-based page; defaults to current request page
     *
     * @return LengthAwarePaginator
     */
    public function getRelatedListings(Listing $listing, int $perPage = 6, ?int $page = null): LengthAwarePaginator
    {
        $cfg = $this->relatedListingsConfig();
        $page = $page ?? LengthAwarePaginator::resolveCurrentPage();
        $maxPer = min((int) $cfg['max_pool'], (int) ($cfg['max_per_page'] ?? 24));
        $perPage = max(1, min($maxPer, $perPage));

        $listing->loadMissing('category');
        $anchorCategoryIds = $this->relatedCategoryIdsForAnchor($listing);
        $ctx = $this->buildRelatedLocationContext($listing);
        $anchorTokens = $this->anchorTokenSetForListing($listing);
        $recencyCol = ($cfg['order_by_published_at'] ?? false) ? 'created_at' : 'updated_at';

        $hideAds = Auth::user()?->settings?->hide_ads ?? false;

        Log::debug('[RelatedListings] start', [
            'listing_id' => $listing->id,
            'category_id' => $ctx['anchorCategoryId'],
            'country' => $ctx['country'] ?? null,
            'country_key' => $ctx['countryKey'] ?? null,
            'has_center' => $ctx['latitude'] !== null && $ctx['longitude'] !== null,
            'anchor_token_count' => count($anchorTokens),
            'per_page' => $perPage,
            'page' => $page,
        ]);

        $byId = [];
        $mergeUnique = function (Collection $chunk) use (&$byId): void {
            foreach ($chunk as $row) {
                if (! isset($byId[$row->id])) {
                    $byId[$row->id] = $row;
                }
            }
        };

        if ($anchorCategoryIds !== []) {
            $catLimit = (int) $cfg['category_limit'];
            $anchorCountry = isset($ctx['country']) ? trim((string) $ctx['country']) : '';
            $hasCountry = $anchorCountry !== '' && mb_strlen($anchorCountry) >= 2;

            if ($hasCountry) {
                $like = '%'.addcslashes($anchorCountry, '%_\\').'%';
                $catInCountry = $this->baseRelatedQuery($listing)
                    ->with('category')
                    ->whereIn('service_category', $anchorCategoryIds)
                    ->where(function ($q) use ($like) {
                        $q->where('additional_info->location->country', 'like', $like)
                            ->orWhere('additional_info->location->address', 'like', $like);
                    })
                    ->orderByDesc($recencyCol)
                    ->limit($catLimit);
                $rowsInCountry = $catInCountry->get();
                $mergeUnique($rowsInCountry);

                $ids = array_keys($byId);
                $catRest = $this->baseRelatedQuery($listing)
                    ->with('category')
                    ->whereIn('service_category', $anchorCategoryIds)
                    ->when($ids !== [], fn ($q) => $q->whereNotIn('id', $ids))
                    ->orderByDesc($recencyCol)
                    ->limit($catLimit);
                $rowsRest = $catRest->get();
                $mergeUnique($rowsRest);

                Log::debug('[RelatedListings] query_category', [
                    'category_ids' => $anchorCategoryIds,
                    'country_scoped' => true,
                    'docs_in_country' => $rowsInCountry->count(),
                    'docs_other' => $rowsRest->count(),
                    'docs_total' => $rowsInCountry->count() + $rowsRest->count(),
                ]);
            } else {
                $qCat = $this->baseRelatedQuery($listing)
                    ->with('category')
                    ->whereIn('service_category', $anchorCategoryIds)
                    ->orderByDesc($recencyCol)
                    ->limit($catLimit);
                $catRows = $qCat->get();
                Log::debug('[RelatedListings] query_category', [
                    'category_ids' => $anchorCategoryIds,
                    'country_scoped' => false,
                    'docs' => $catRows->count(),
                ]);
                $mergeUnique($catRows);
            }
        }

        if ($listing->store_id) {
            $storeLimit = (int) ($cfg['store_limit'] ?? 80);
            $storeFetched = $this->baseRelatedQuery($listing)
                ->with('category')
                ->where('store_id', $listing->store_id)
                ->orderByDesc($recencyCol)
                ->limit($storeLimit)
                ->get();
            $mergeUnique($storeFetched);
            Log::debug('[RelatedListings] query_store', [
                'store_id' => $listing->store_id,
                'docs' => $storeFetched->count(),
            ]);
        }

        if ($anchorCategoryIds === []) {
            $nameKeys = $this->categoryNameKeysFromListing($listing);
            $scanLimit = (int) $cfg['name_anchor_scan'];
            $scanned = $this->baseRelatedQuery($listing)
                ->with('category')
                ->orderByDesc($recencyCol)
                ->limit($scanLimit)
                ->get();
            $matched = $scanned->filter(function (Listing $L) use ($nameKeys) {
                $k = $this->normalizeCategoryNameKey($L->category?->name ?? '');

                return $k !== '' && isset($nameKeys[$k]);
            })->values();
            Log::debug('[RelatedListings] query_name_anchor', [
                'scanned' => $scanned->count(),
                'unique' => $matched->count(),
            ]);
            $mergeUnique($matched);
        }

        if ($ctx['latitude'] !== null && $ctx['longitude'] !== null && $this->relatedGeoQuerySupported()) {
            $radius = (float) $cfg['radius_km'];
            $lat = $ctx['latitude'];
            $lng = $ctx['longitude'];
            try {
                $geoQuery = $this->baseRelatedQuery($listing)
                    ->with('category')
                    ->when($anchorCategoryIds !== [], function ($q) use ($anchorCategoryIds) {
                        $q->whereIn('service_category', $anchorCategoryIds);
                    })
                    ->whereRaw(
                        "(6371 * acos(cos(radians(?)) * cos(radians(JSON_UNQUOTE(JSON_EXTRACT(additional_info, '$.location.lat'))))
                        * cos(radians(JSON_UNQUOTE(JSON_EXTRACT(additional_info, '$.location.long'))) - radians(?))
                        + sin(radians(?)) * sin(radians(JSON_UNQUOTE(JSON_EXTRACT(additional_info, '$.location.lat')))))) <= ?",
                        [$lat, $lng, $lat, $radius]
                    )
                    ->orderByDesc($recencyCol)
                    ->limit((int) $cfg['geo_limit']);
                $geoRows = $geoQuery->get();
                $before = count($byId);
                $mergeUnique($geoRows);
                Log::debug('[RelatedListings] query_geo', [
                    'radius_km' => $radius,
                    'docs' => $geoRows->count(),
                    'added_unique' => count($byId) - $before,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[RelatedListings] query_geo_failed', ['message' => $e->getMessage()]);
            }
        }

        $pool = collect(array_values($byId));
        $pool = $this->finalizeRelatedListings($pool, $listing, $hideAds);

        Log::debug('[RelatedListings] after_finalize', ['count' => $pool->count()]);

        if ($pool->count() < (int) $cfg['min_before_fallback']) {
            Log::debug('[RelatedListings] fallback_trigger', [
                'pool' => $pool->count(),
                'min' => (int) $cfg['min_before_fallback'],
            ]);
            $existingIds = $pool->pluck('id')->all();
            $take = (int) $cfg['global_fallback_take'];
            $added = 0;
            $anchorCountry = isset($ctx['country']) ? trim((string) $ctx['country']) : '';
            $hasCountry = $anchorCountry !== '' && mb_strlen($anchorCountry) >= 2;

            if ($hasCountry) {
                $like = '%'.addcslashes($anchorCountry, '%_\\').'%';
                $globalInCountry = $this->baseRelatedQuery($listing)
                    ->with('category')
                    ->where(function ($q) use ($like) {
                        $q->where('additional_info->location->country', 'like', $like)
                            ->orWhere('additional_info->location->address', 'like', $like);
                    })
                    ->orderByDesc($recencyCol)
                    ->limit((int) $cfg['global_fallback_fetch'])
                    ->get();
                foreach ($globalInCountry as $row) {
                    if (in_array($row->id, $existingIds, true)) {
                        continue;
                    }
                    $pool->push($row);
                    $existingIds[] = $row->id;
                    $added++;
                    if ($added >= $take) {
                        break;
                    }
                }
            }

            if ($added < $take) {
                $global = $this->baseRelatedQuery($listing)
                    ->with('category')
                    ->orderByDesc($recencyCol)
                    ->limit((int) $cfg['global_fallback_fetch'])
                    ->get();
                foreach ($global as $row) {
                    if (in_array($row->id, $existingIds, true)) {
                        continue;
                    }
                    $pool->push($row);
                    $existingIds[] = $row->id;
                    $added++;
                    if ($added >= $take) {
                        break;
                    }
                }
            }

            $pool = $this->finalizeRelatedListings($pool, $listing, $hideAds);
            Log::debug('[RelatedListings] after_fallback_global', ['count' => $pool->count()]);
        }

        $pool = $this->sortRelatedPool($pool, $listing, $ctx, $anchorTokens, $anchorCategoryIds);
        $maxPool = (int) $cfg['max_pool'];
        if ($pool->count() > $maxPool) {
            $pool = $pool->take($maxPool)->values();
        }

        $total = $pool->count();
        $offset = ($page - 1) * $perPage;
        $slice = EloquentCollection::make($pool->slice($offset, $perPage)->all());
        $slice->loadMissing(['user', 'store', 'category']);

        Log::debug('[RelatedListings] done', [
            'returned' => $slice->count(),
            'pool_total' => $total,
        ]);

        return new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    protected function baseRelatedQuery(Listing $exclude): Builder
    {
        return Listing::query()
            ->whereKeyNot($exclude->id)
            ->where(function ($q) {
                $q->where('availability', true)->orWhereNull('availability');
            });
    }

    /**
     * Defaults merged with config/listing.php so missing config or deploy without the file never yields null offsets.
     *
     * @return array{
     *   radius_km: float,
     *   max_pool: int,
     *   min_before_fallback: int,
     *   category_limit: int,
     *   geo_limit: int,
     *   name_anchor_scan: int,
     *   global_fallback_fetch: int,
     *   global_fallback_take: int,
     *   order_by_published_at: bool
     * }
     */
    protected function relatedListingsConfig(): array
    {
        $defaults = [
            'per_page' => 10,
            'max_per_page' => 24,
            'radius_km' => 50.0,
            'max_pool' => 96,
            'min_before_fallback' => 12,
            'category_limit' => 150,
            'store_limit' => 80,
            'geo_limit' => 100,
            'name_anchor_scan' => 220,
            'global_fallback_fetch' => 500,
            'global_fallback_take' => 100,
            'order_by_published_at' => false,
        ];
        $fromConfig = config('listing.related');
        if (! is_array($fromConfig)) {
            return $defaults;
        }

        return array_merge($defaults, $fromConfig);
    }

    protected function relatedGeoQuerySupported(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Exact anchor category id for related queries (same category as the listing only).
     *
     * @return list<int>
     */
    protected function relatedCategoryIdsForAnchor(Listing $listing): array
    {
        if ($listing->service_category === null) {
            return [];
        }

        return [(int) $listing->service_category];
    }

    /**
     * Safe read of additional_info.location (additional_info may be null).
     *
     * @return array<string, mixed>
     */
    protected function listingLocationArray(Listing $listing): array
    {
        $add = $listing->additional_info ?? [];
        $loc = $add['location'] ?? null;

        return is_array($loc) ? $loc : [];
    }

    /**
     * @return array{anchorCategoryId: int|null, anchorCategoryNames: list<string>, anchorTitle: string, latitude: float|null, longitude: float|null, city: ?string, state: ?string, country: ?string, countryKey: string, displayLocation: string}
     */
    protected function buildRelatedLocationContext(Listing $listing): array
    {
        $add = $listing->additional_info ?? [];
        $loc = is_array($add['location'] ?? null) ? $add['location'] : [];
        $coords = $this->extractListingCoords($listing);
        $address = isset($loc['address']) ? (string) $loc['address'] : null;
        $city = isset($loc['city']) ? (string) $loc['city'] : null;
        $state = isset($loc['state']) ? (string) $loc['state'] : null;
        $country = isset($loc['country']) ? (string) $loc['country'] : null;
        if ($address && ($city === null || $city === '' || $state === null || $state === '')) {
            $parts = array_map('trim', explode(',', $address));
            if (count($parts) >= 2) {
                $city = ($city === null || $city === '') ? $parts[0] : $city;
                $state = ($state === null || $state === '') ? ($parts[1] ?? $state) : $state;
                $country = ($country === null || $country === '') ? ($parts[2] ?? $country) : $country;
            }
        }
        if (($country === null || $country === '') && is_string($address) && $address !== '') {
            $parts = array_map('trim', explode(',', $address));
            if (count($parts) >= 2) {
                $country = $parts[count($parts) - 1] ?: null;
            }
        }

        $countryKey = $this->normalizeCountryKey($country);

        return [
            'anchorCategoryId' => $listing->service_category ? (int) $listing->service_category : null,
            'anchorCategoryNames' => $listing->category ? [$listing->category->name] : [],
            'anchorTitle' => (string) ($listing->title ?? ''),
            'latitude' => $coords[0] ?? null,
            'longitude' => $coords[1] ?? null,
            'city' => $city,
            'state' => $state,
            'country' => $country !== null && $country !== '' ? $country : null,
            'countryKey' => $countryKey,
            'displayLocation' => $address ?? '',
        ];
    }

    /**
     * Normalized country token for fuzzy matching (letters/digits only, lowercased).
     */
    protected function normalizeCountryKey(?string $country): string
    {
        if ($country === null || $country === '') {
            return '';
        }
        $s = mb_strtolower(trim($country));

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    }

    /**
     * Map common aliases to a single token so "USA" / "United States" match.
     */
    protected function canonicalCountryKey(string $normalizedKey): string
    {
        if ($normalizedKey === '') {
            return '';
        }
        static $aliases = [
            'usa' => 'us',
            'us' => 'us',
            'unitedstates' => 'us',
            'unitedstatesofamerica' => 'us',
            'uk' => 'gb',
            'gb' => 'gb',
            'unitedkingdom' => 'gb',
            'greatbritain' => 'gb',
            'uae' => 'ae',
            'uaeemirates' => 'ae',
        ];

        return $aliases[$normalizedKey] ?? $normalizedKey;
    }

    /**
     * Country string for a listing (JSON country field, else last segment of address).
     */
    protected function resolveCountryFromListing(Listing $listing): ?string
    {
        $loc = $this->listingLocationArray($listing);
        if ($loc === []) {
            return null;
        }
        $c = $loc['country'] ?? null;
        if (is_string($c) && trim($c) !== '') {
            return trim($c);
        }
        $addr = $loc['address'] ?? null;
        if (is_string($addr) && trim($addr) !== '') {
            $parts = array_map('trim', explode(',', $addr));
            if (count($parts) >= 2) {
                $last = $parts[count($parts) - 1];

                return $last !== '' ? $last : null;
            }
        }

        return null;
    }

    /**
     * True if candidate listing is in the same country as anchor context (fuzzy).
     *
     * @param  array<string, mixed>  $ctx
     */
    protected function listingMatchesCountryContext(Listing $listing, array $ctx): bool
    {
        $anchorCountry = $ctx['country'] ?? null;
        $anchorKey = $ctx['countryKey'] ?? '';
        if (($anchorCountry === null || $anchorCountry === '') && $anchorKey === '') {
            return false;
        }
        if ($anchorKey === '') {
            $anchorKey = $this->normalizeCountryKey(is_string($anchorCountry) ? $anchorCountry : null);
        }
        $anchorCanon = $this->canonicalCountryKey($anchorKey);
        $cand = $this->resolveCountryFromListing($listing);
        $candKey = $this->normalizeCountryKey($cand);
        if ($candKey === '') {
            return false;
        }
        $candCanon = $this->canonicalCountryKey($candKey);
        if ($anchorCanon !== '' && $candCanon !== '' && $anchorCanon === $candCanon) {
            return true;
        }
        if ($anchorKey !== '' && $candKey !== '') {
            if ($anchorKey === $candKey) {
                return true;
            }
            if (mb_strlen($anchorKey) >= 4 && mb_strlen($candKey) >= 4) {
                if (str_contains($candKey, $anchorKey) || str_contains($anchorKey, $candKey)) {
                    return true;
                }
            }
        }
        $addr = mb_strtolower((string) (($this->listingLocationArray($listing)['address'] ?? '') ?: ''));
        if (is_string($anchorCountry) && mb_strlen(trim($anchorCountry)) >= 3) {
            $needle = mb_strtolower(trim($anchorCountry));
            if ($needle !== '' && str_contains($addr, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    protected function extractListingCoords(Listing $listing): ?array
    {
        $loc = $this->listingLocationArray($listing);
        if ($loc === []) {
            return null;
        }
        $lat = $loc['lat'] ?? null;
        $lng = $loc['long'] ?? $loc['lng'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        return [$lat, $lng];
    }

    /**
     * @return array<string, bool>
     */
    protected function categoryNameKeysFromListing(Listing $listing): array
    {
        $keys = [];
        $listing->loadMissing('category');
        if ($listing->category) {
            $k = $this->normalizeCategoryNameKey($listing->category->name);
            if ($k !== '') {
                $keys[$k] = true;
            }
        }
        foreach (($listing->additional_info ?? [])['anchorCategoryNames'] ?? [] as $n) {
            if (is_string($n)) {
                $k = $this->normalizeCategoryNameKey($n);
                if ($k !== '') {
                    $keys[$k] = true;
                }
            }
        }

        return $keys;
    }

    protected function normalizeCategoryNameKey(?string $name): string
    {
        if ($name === null || $name === '') {
            return '';
        }
        $s = mb_strtolower(trim($name));

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    }

    /**
     * Loose token set for title + tags + keywords (anchor).
     *
     * @return array<string, bool>
     */
    protected function anchorTokenSetForListing(Listing $listing): array
    {
        $tokens = [];
        $title = (string) ($listing->title ?? '');
        foreach (preg_split('/[\s\-_,.]+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
            $t = mb_strtolower(trim((string) $t));
            if (mb_strlen($t) >= 2) {
                $tokens[$t] = true;
            }
        }
        foreach ($this->anchorTagsFromListing($listing) as $t) {
            $t = mb_strtolower(trim((string) $t));
            if (mb_strlen($t) >= 2) {
                $tokens[$t] = true;
            }
        }

        return $tokens;
    }

    /**
     * @return list<string>
     */
    protected function anchorTagsFromListing(Listing $listing): array
    {
        $tags = [];
        $add = $listing->additional_info ?? [];
        $ft = $add['firebase']['tags'] ?? $add['tags'] ?? null;
        if (is_string($ft)) {
            $tags = array_merge($tags, array_map('trim', explode(',', $ft)));
        } elseif (is_array($ft)) {
            foreach ($ft as $v) {
                if (is_string($v) || is_numeric($v)) {
                    $tags[] = (string) $v;
                }
            }
        }
        $adv = $listing->advance_options ?? [];
        if (isset($adv['tags']) && is_array($adv['tags'])) {
            foreach ($adv['tags'] as $v) {
                if (is_string($v) || is_numeric($v)) {
                    $tags[] = (string) $v;
                }
            }
        }
        $kw = (string) ($listing->search_keyword ?? '');
        if ($kw !== '') {
            $tags = array_merge($tags, array_map('trim', explode(',', $kw)));
        }

        return array_values(array_unique(array_filter(array_map('trim', $tags))));
    }

    protected function listingLooselyMatchesAnchorTokens(Listing $listing, array $anchorTokens): bool
    {
        if ($anchorTokens === []) {
            return false;
        }
        $haystack = mb_strtolower(
            ($listing->title ?? '').' '.($listing->search_keyword ?? '').' '.implode(' ', $this->anchorTagsFromListing($listing))
        );
        foreach (array_keys($anchorTokens) as $tok) {
            if ($tok !== '' && mb_strpos($haystack, $tok) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $ctx  from buildRelatedLocationContext
     */
    protected function listingMatchesCityContext(Listing $listing, array $ctx): bool
    {
        $loc = $this->listingLocationArray($listing);
        if ($loc === []) {
            return false;
        }
        $addr = mb_strtolower((string) ($loc['address'] ?? ''));
        $city = $ctx['city'] ?? null;
        $state = $ctx['state'] ?? null;
        if (is_string($city) && mb_strlen($city) >= 2 && str_contains($addr, mb_strtolower($city))) {
            return true;
        }
        if (is_string($state) && mb_strlen($state) >= 2 && str_contains($addr, mb_strtolower($state))) {
            return true;
        }
        if (is_string($city) && mb_strlen($city) >= 2) {
            $lc = mb_strtolower((string) ($loc['city'] ?? ''));
            if ($lc !== '' && str_contains($lc, mb_strtolower($city))) {
                return true;
            }
        }

        return false;
    }

    protected function relatedListingRecencyMs(Listing $listing): int
    {
        $useCreated = (bool) config('listing.related.order_by_published_at', false);
        $t = $useCreated ? $listing->created_at : $listing->updated_at;

        return $t ? (int) $t->timestamp : 0;
    }

    protected function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    protected function distanceFromAnchorKm(Listing $listing, ?float $lat, ?float $lng): ?float
    {
        if ($lat === null || $lng === null) {
            return null;
        }
        $c = $this->extractListingCoords($listing);
        if ($c === null) {
            return null;
        }

        return $this->haversineKm($lat, $lng, $c[0], $c[1]);
    }

    /**
     * @param  Collection<int, Listing>  $pool
     * @return Collection<int, Listing>
     */
    protected function finalizeRelatedListings(Collection $pool, Listing $exclude, bool $hideAds): Collection
    {
        return $pool->filter(function (Listing $L) use ($exclude, $hideAds) {
            if ((int) $L->id === (int) $exclude->id) {
                return false;
            }
            if ($L->availability === false) {
                return false;
            }
            if ($hideAds && $L->service_type === Listing::OFFER_SERVICE) {
                return false;
            }

            return true;
        })->values();
    }

    /**
     * @param  Collection<int, Listing>  $pool
     * @param  list<int>  $anchorCategoryIds
     * @param  array<string, mixed>  $ctx
     * @param  array<string, bool>  $anchorTokens
     * @return Collection<int, Listing>
     */
    protected function sortRelatedPool(Collection $pool, Listing $anchor, array $ctx, array $anchorTokens, array $anchorCategoryIds): Collection
    {
        $anchor->loadMissing('category');
        $nameKeys = $this->categoryNameKeysFromListing($anchor);
        $hasCenter = $ctx['latitude'] !== null && $ctx['longitude'] !== null;
        $lat = $ctx['latitude'];
        $lng = $ctx['longitude'];
        $anchorStoreId = $anchor->store_id ? (int) $anchor->store_id : null;

        $rows = [];
        foreach ($pool as $L) {
            $L->loadMissing('category');
            $sameCategoryFamily = false;
            if ($anchorCategoryIds !== []) {
                $sameCategoryFamily = in_array((int) $L->service_category, $anchorCategoryIds, true);
            } elseif ($nameKeys !== []) {
                $nm = $this->normalizeCategoryNameKey($L->category?->name ?? '');
                $sameCategoryFamily = $nm !== '' && isset($nameKeys[$nm]);
            }
            $sameStore = $anchorStoreId !== null
                && $L->store_id !== null
                && (int) $L->store_id === $anchorStoreId;
            // Tier: 3 = same category family, 2 = same store (other categories), 1 = rest
            $tier = $sameCategoryFamily ? 3 : ($sameStore ? 2 : 1);
            $countryBoost = $this->listingMatchesCountryContext($L, $ctx) ? 1 : 0;
            $cityBoost = $this->listingMatchesCityContext($L, $ctx) ? 1 : 0;
            $kwBoost = $this->listingLooselyMatchesAnchorTokens($L, $anchorTokens) ? 1 : 0;
            $dist = $hasCenter ? $this->distanceFromAnchorKm($L, $lat, $lng) : null;
            $recency = $this->relatedListingRecencyMs($L);
            $rows[] = [
                'l' => $L,
                'tier' => $tier,
                'countryBoost' => $countryBoost,
                'cityBoost' => $cityBoost,
                'kwBoost' => $kwBoost,
                'dist' => $dist,
                'recency' => $recency,
            ];
        }

        usort($rows, function (array $a, array $b) use ($hasCenter) {
            if ($a['tier'] !== $b['tier']) {
                return $b['tier'] <=> $a['tier'];
            }
            if ($a['countryBoost'] !== $b['countryBoost']) {
                return $b['countryBoost'] <=> $a['countryBoost'];
            }
            if ($a['cityBoost'] !== $b['cityBoost']) {
                return $b['cityBoost'] <=> $a['cityBoost'];
            }
            if ($a['kwBoost'] !== $b['kwBoost']) {
                return $b['kwBoost'] <=> $a['kwBoost'];
            }
            if ($hasCenter && $a['dist'] !== null && $b['dist'] !== null) {
                if (abs($a['dist'] - $b['dist']) > 0.25) {
                    return $a['dist'] <=> $b['dist'];
                }
            }

            if ($a['recency'] !== $b['recency']) {
                return $b['recency'] <=> $a['recency'];
            }

            // Stable order across paginated requests (same tier / same country / same recency).
            return $a['l']->id <=> $b['l']->id;
        });

        return collect(array_column($rows, 'l'));
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
