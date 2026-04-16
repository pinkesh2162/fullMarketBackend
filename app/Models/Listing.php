<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Listing extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, SoftDeletes;

    const LISTING_IMAGES = 'listing_images';

    protected $fillable = [
        'firebase_id',
        'user_id',
        'store_id',
        'service_type',
        'title',
        'service_category',
        'service_modality',
        'description',
        'search_keyword',
        'contact_info',
        'additional_info',
        'currency',
        'price',
        'availability',
        'condition',
        'listing_type',
        'property_type',
        'bedrooms',
        'bathrooms',
        'advance_options',
        'views_count',
        'vehicle_type',
        'vehical_info',
        'fual_type',
        'transmission',
        'deleted_at',
    ];

    const FILTER_PARAMS = [
        'category',
        'location',
        'lat',
        'long',
        'lng',
        'radius',
        'hide_ads',
        'title',
        'search_keyword',
        'store_id',
    ];

    // availability
    const AVAILABLE = true;

    const SOLD = false;

    // listing_type
    const FOR_SALE = 1;

    const FOR_RENT = 0;

    // listing service_type types
    const OFFER_SERVICE = 'offer_service';

    const ARTICLE_FOR_SALE = 'article_for_sale';

    const PROPERTY_FOR_SALE = 'property_for_sale';

    const VEHICLE_FOR_SALE = 'vehicle_for_sale';

    protected $casts = [
        'service_modality' => 'string',
        'contact_info' => 'array',
        'additional_info' => 'array',
        'advance_options' => 'array',
        'vehical_info' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'service_category');
    }

    /**
     * For authenticated viewers: omit listings they hid or sellers/stores they blocked (feeds, search, related).
     * Detail views (e.g. GET listing by id) intentionally do not use this scope.
     */
    public function scopeVisibleToUserId(Builder $query, ?int $userId): Builder
    {
        if ($userId === null || $userId < 1) {
            return $query;
        }

        return $query
            ->whereNotExists(function ($q) use ($userId) {
                $q->from('user_hidden_listings as uhl')
                    ->whereColumn('uhl.listing_id', 'listings.id')
                    ->where('uhl.user_id', $userId);
            })
            ->whereNotExists(function ($q) use ($userId) {
                $q->from('user_blocked_stores as ubs')
                    ->whereColumn('ubs.store_id', 'listings.store_id')
                    ->where('ubs.user_id', $userId)
                    ->whereNotNull('listings.store_id');
            })
            ->whereNotExists(function ($q) use ($userId) {
                $q->from('user_blocked_sellers as ubsl')
                    ->whereColumn('ubsl.blocked_user_id', 'listings.user_id')
                    ->where('ubsl.user_id', $userId);
            });
    }

    public function getImagesAttribute()
    {
        $media = $this->getMedia(self::LISTING_IMAGES)->sortBy(function ($item) {
            return $item->order_column ?? $item->id;
        })->values();
        if ($media->isNotEmpty()) {
            return $media->map(function ($item) {
                return $item->getFullUrl();
            });
        }

        return [];
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['title'] ?? false, function ($q, $title) {
            $q->where('title', 'like', "%{$title}%");
        });

        $query->when($filters['store_id'] ?? false, function ($q, $store_id) {
            $q->where('store_id', $store_id);
        });

        $query->when($filters['search_keyword'] ?? false, function ($q, $search_keyword) {
            $term = '%'.addcslashes((string) $search_keyword, '%_\\').'%';
            $q->where(function ($q2) use ($term) {
                $q2->where('search_keyword', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        });

        $query->when($filters['category'] ?? false, function ($q, $categoryFilter) {
            $categoryIds = self::categoryIdsForFilterIncludingDescendants($categoryFilter);
            if ($categoryIds === []) {
                $q->whereRaw('1 = 0');
            } else {
                $q->whereIn('service_category', $categoryIds);
            }
        });

        $query->when($filters['location'] ?? false, function ($q, $location) {
            $q->where('additional_info->location->address', 'like', "%{$location}%");
        });

        if (isset($filters['lat'], $filters['long'], $filters['radius'])) {
            $lat = $filters['lat'];
            $long = $filters['long'];
            $radius = max(0, min($filters['radius'] ?? 0, 500));

            $query->whereRaw("
                (6371 * acos(cos(radians(?)) * cos(radians(JSON_UNQUOTE(JSON_EXTRACT(additional_info, '$.location.lat'))))
                * cos(radians(JSON_UNQUOTE(JSON_EXTRACT(additional_info, '$.location.long'))) - radians(?))
                + sin(radians(?)) * sin(radians(JSON_UNQUOTE(JSON_EXTRACT(additional_info, '$.location.lat')))))) <= ?
            ", [$lat, $long, $lat, $radius]);
        }
    }

    /**
     * Same `service_category` id set as `GET /listings?category=…` (id or name).
     * Use for aggregations (e.g. most-used categories) so counts match listing search.
     *
     * @param  string|int|array<string|int>  $category
     * @return list<int>
     */
    public static function serviceCategoryIdsForFilter(string|int|array $category): array
    {
        return self::categoryIdsForFilterIncludingDescendants($category);
    }

    /**
     * Category ids for listing filter: name or id match, plus all descendants (sub-categories).
     *
     * Name search loads all categories once, matches in PHP (normalization + tokens), and also
     * matches when the **parent** row name matches so listings stored under sub-categories still
     * resolve when filtering by the main category label. Each matched id is expanded with BFS
     * to include its full subtree (main + all nested sub-categories).
     *
     * @param  string|int|array<string|int>  $category
     * @return list<int>
     */
    protected static function categoryIdsForFilterIncludingDescendants(string|int|array $category): array
    {
        if (is_array($category)) {
            $category = reset($category);
            if ($category === false) {
                return [];
            }
        }

        if (is_string($category)) {
            $category = trim(preg_replace('/\s+/u', ' ', $category) ?? $category);
            if ($category === '') {
                return [];
            }
            if (ctype_digit($category)) {
                $category = (int) $category;
            }
        }

        $rows = DB::table('categories')->select('id', 'parent_id', 'name')->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->id] = $row;
        }

        $childrenByParent = [];
        foreach ($rows as $row) {
            if ($row->parent_id === null) {
                continue;
            }
            $p = (int) $row->parent_id;
            if (! isset($childrenByParent[$p])) {
                $childrenByParent[$p] = [];
            }
            $childrenByParent[$p][] = (int) $row->id;
        }

        if (is_int($category) || (is_string($category) && ctype_digit((string) $category))) {
            $id = (int) $category;
            if (! isset($byId[$id])) {
                return [];
            }

            return self::categorySubtreeIdsBreadthFirst($id, $childrenByParent);
        }

        $search = (string) $category;
        $expandRoots = [];

        foreach ($rows as $row) {
            $rid = (int) $row->id;
            if (self::categoryFilterNameMatches($search, (string) $row->name)) {
                $expandRoots[$rid] = true;
            }
        }

        foreach ($rows as $row) {
            if ($row->parent_id === null) {
                continue;
            }
            $pid = (int) $row->parent_id;
            $parent = $byId[$pid] ?? null;
            if ($parent !== null && self::categoryFilterNameMatches($search, (string) $parent->name)) {
                $expandRoots[$pid] = true;
            }
        }

        if ($expandRoots === []) {
            return [];
        }

        $merged = [];
        foreach (array_keys($expandRoots) as $rootId) {
            foreach (self::categorySubtreeIdsBreadthFirst((int) $rootId, $childrenByParent) as $cid) {
                $merged[$cid] = true;
            }
        }

        return array_map('intval', array_keys($merged));
    }

    /**
     * @param  array<int, list<int>>  $childrenByParent
     * @return list<int>
     */
    protected static function categorySubtreeIdsBreadthFirst(int $rootId, array $childrenByParent): array
    {
        $result = [];
        $queue = [$rootId];
        $seen = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $result[] = $id;
            foreach ($childrenByParent[$id] ?? [] as $childId) {
                if (! isset($seen[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        return $result;
    }

    /**
     * Flexible name match for API filter (case, spacing, punctuation).
     */
    protected static function categoryFilterNameMatches(string $search, string $categoryName): bool
    {
        $a = self::normalizeCategoryFilterString($search);
        $b = self::normalizeCategoryFilterString($categoryName);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        if (mb_strlen($a) >= 3 && str_contains($b, $a)) {
            return true;
        }
        $tokens = array_values(array_filter(explode(' ', $a), fn ($t) => mb_strlen($t) >= 2));
        if ($tokens === []) {
            return false;
        }
        foreach ($tokens as $t) {
            if (! str_contains($b, $t)) {
                return false;
            }
        }

        return true;
    }

    protected static function normalizeCategoryFilterString(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }
}
