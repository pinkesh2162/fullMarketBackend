<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
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

        $locationTrim = trim((string) ($filters['location'] ?? ''));
        $hasTextLocation = $locationTrim !== '';
        $hasGeo = isset($filters['lat'], $filters['long'], $filters['radius']);
        $lat = $hasGeo ? (float) $filters['lat'] : null;
        $lng = $hasGeo ? (float) $filters['long'] : null;
        $radiusKm = $hasGeo ? max(0.0, min((float) ($filters['radius'] ?? 0), 500.0)) : 0.0;
        $applyGeo = $hasGeo && $radiusKm > 0.0;

        if ($hasTextLocation && $applyGeo) {
            $haversineSql = self::sqlHaversineKmWithinRadiusOnAdditionalInfo();
            $noCoordsSql = self::sqlListingLacksValidAdditionalInfoLatLong();
            $bindings = [$lat, $lng, $lat, $radiusKm];
            $query->where(function (Builder $outer) use ($locationTrim, $haversineSql, $noCoordsSql, $bindings) {
                $outer->where(function (Builder $mid) use ($haversineSql, $noCoordsSql, $bindings) {
                    $mid->whereRaw($haversineSql, $bindings)
                        ->orWhereRaw('('.$noCoordsSql.')');
                });
                $outer->where(function (Builder $text) use ($locationTrim, $haversineSql, $bindings) {
                    $text->where(function (Builder $strict) use ($locationTrim) {
                        self::applyLocationTextStrictToQuery($strict, $locationTrim);
                    })->orWhere(function (Builder $anchor) use ($locationTrim, $haversineSql, $bindings) {
                        self::applyWithinRadiusAnchorOrSparseToQuery($anchor, $locationTrim, $haversineSql, $bindings);
                    });
                });
            });
        } elseif ($hasTextLocation) {
            $query->where(function (Builder $q) use ($locationTrim) {
                self::applyLocationTextStrictToQuery($q, $locationTrim);
            });
        } elseif ($applyGeo) {
            $query->whereRaw(
                self::sqlHaversineKmWithinRadiusOnAdditionalInfo(),
                [$lat, $lng, $lat, $radiusKm]
            );
        }
    }

    /**
     * Lowercased single string of address + city + state + country for location text matching.
     */
    protected static function sqlLocationTextBlobLower(): string
    {
        return 'LOWER(CONCAT_WS(\' \',
            IFNULL(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.address\')), \'\'),
            IFNULL(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.city\')), \'\'),
            IFNULL(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.state\')), \'\'),
            IFNULL(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.country\')), \'\')
        ))';
    }

    /**
     * True when the listing has no usable lat/long in additional_info (radius cannot apply).
     */
    protected static function sqlListingLacksValidAdditionalInfoLatLong(): string
    {
        $lat = self::sqlAdditionalInfoLatitudeScalarExpr();
        $lng = self::sqlAdditionalInfoLongitudeScalarExpr();

        return "(
            {$lat} IS NULL OR {$lat} = '' OR {$lng} IS NULL OR {$lng} = ''
            OR NOT (
                CAST({$lat} AS DECIMAL(12,8)) BETWEEN -90 AND 90
                AND CAST({$lng} AS DECIMAL(12,8)) BETWEEN -180 AND 180
            )
        )";
    }

    protected static function sqlAdditionalInfoLatitudeScalarExpr(): string
    {
        return 'COALESCE(
            NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.lat\'))), \'\'),
            NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.latitude\'))), \'\')
        )';
    }

    protected static function sqlAdditionalInfoLongitudeScalarExpr(): string
    {
        return 'COALESCE(
            NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.long\'))), \'\'),
            NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.lng\'))), \'\'),
            NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.longitude\'))), \'\')
        )';
    }

    /**
     * Comma-separated place labels (e.g. "Ciudad de México, CDMX, Mexico") → require each
     * non-generic segment on the location blob (AND), so "Mexico" alone does not match Estado de México.
     *
     * @return list<string>
     */
    protected static function significantLocationSegments(string $locationTrim): array
    {
        $parts = preg_split('/\s*,\s*/u', $locationTrim) ?: [];
        $generic = self::genericLocationTokensLower();
        $usAbbrevs = self::usStateAbbrevToNameLower();
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            if (preg_match('/^\d[\d\s\-]*$/u', $p)) {
                continue;
            }
            $key = mb_strtolower($p);
            if (isset($generic[$key])) {
                continue;
            }
            if (mb_strlen($p) === 2 && ctype_alpha($p) && ! isset($usAbbrevs[mb_strtoupper($p)])) {
                continue;
            }
            $out[] = $p;
        }

        return $out;
    }

    /**
     * @return array<string, true> Lowercased country/region tokens excluded from mandatory AND matching.
     */
    protected static function genericLocationTokensLower(): array
    {
        $keys = [
            'mexico', 'méxico', 'mx', 'usa', 'us', 'u.s.', 'u.s.a.', 'united states', 'united states of america',
            'canada', 'canadá', 'uk', 'u.k.', 'united kingdom', 'spain', 'españa', 'espana',
        ];

        return array_fill_keys($keys, true);
    }

    /**
     * LIKE patterns (without %) for one user segment; any one match counts as that segment satisfied.
     *
     * @return list<string>
     */
    protected static function segmentSearchNeedles(string $segment): array
    {
        $s = mb_strtolower(trim($segment));
        if ($s === '') {
            return [];
        }
        if (preg_match('/^ciudad\s+de\s+m[eé]xico$/u', $s) || $s === 'mexico city') {
            return ['ciudad de méxico', 'ciudad de mexico', 'mexico city'];
        }
        if ($s === 'cdmx' || $s === 'c.d.m.x.' || $s === 'df' || $s === 'd.f.' || $s === 'distrito federal') {
            return ['cdmx', 'distrito federal', 'ciudad de méxico', 'ciudad de mexico'];
        }
        if (str_contains($s, 'cdmx')) {
            return [$s];
        }

        $us = self::usStateNeedlesForSegment($segment);
        if ($us !== []) {
            return $us;
        }

        return [mb_strtolower($segment)];
    }

    /**
     * @return array<string, string> Uppercase 2-letter US state code => lowercase full name.
     */
    protected static function usStateAbbrevToNameLower(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $pairs = [
            'AL' => 'alabama', 'AK' => 'alaska', 'AZ' => 'arizona', 'AR' => 'arkansas', 'CA' => 'california',
            'CO' => 'colorado', 'CT' => 'connecticut', 'DE' => 'delaware', 'FL' => 'florida', 'GA' => 'georgia',
            'HI' => 'hawaii', 'ID' => 'idaho', 'IL' => 'illinois', 'IN' => 'indiana', 'IA' => 'iowa',
            'KS' => 'kansas', 'KY' => 'kentucky', 'LA' => 'louisiana', 'ME' => 'maine', 'MD' => 'maryland',
            'MA' => 'massachusetts', 'MI' => 'michigan', 'MN' => 'minnesota', 'MS' => 'mississippi', 'MO' => 'missouri',
            'MT' => 'montana', 'NE' => 'nebraska', 'NV' => 'nevada', 'NH' => 'new hampshire', 'NJ' => 'new jersey',
            'NM' => 'new mexico', 'NY' => 'new york', 'NC' => 'north carolina', 'ND' => 'north dakota', 'OH' => 'ohio',
            'OK' => 'oklahoma', 'OR' => 'oregon', 'PA' => 'pennsylvania', 'RI' => 'rhode island', 'SC' => 'south carolina',
            'SD' => 'south dakota', 'TN' => 'tennessee', 'TX' => 'texas', 'UT' => 'utah', 'VT' => 'vermont',
            'VA' => 'virginia', 'WA' => 'washington', 'WV' => 'west virginia', 'WI' => 'wisconsin', 'WY' => 'wyoming',
            'DC' => 'district of columbia',
        ];
        $map = $pairs;

        return $map;
    }

    /**
     * LIKE needles (without surrounding %) for a US state segment (abbrev or full name).
     *
     * @return list<string>
     */
    protected static function usStateNeedlesForSegment(string $segment): array
    {
        $t = trim($segment);
        if ($t === '') {
            return [];
        }
        $byAbbrev = self::usStateAbbrevToNameLower();
        if (mb_strlen($t) === 2 && ctype_alpha($t)) {
            $abbr = mb_strtoupper($t);
            if (! isset($byAbbrev[$abbr])) {
                return [];
            }
            $full = $byAbbrev[$abbr];
            $a = mb_strtolower($abbr);

            return array_values(array_unique([
                $full,
                ', '.$a.',',
                ', '.$a.' ',
                ' '.$a.',',
                ' '.$a.' ',
            ]));
        }
        $needle = mb_strtolower($t);
        foreach ($byAbbrev as $abbr => $full) {
            if ($needle === $full) {
                $a = mb_strtolower($abbr);

                return array_values(array_unique([
                    $full,
                    ', '.$a.',',
                    ', '.$a.' ',
                    ' '.$a.',',
                    ' '.$a.' ',
                ]));
            }
        }

        return [];
    }

    /**
     * Character length of trimmed textual location (address+city+state+country) for “sparse” detection.
     */
    protected static function sqlLocationConcatTrimCharLength(): string
    {
        return 'CHAR_LENGTH(TRIM(CONCAT_WS(\' \',
            IFNULL(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.address\')), \'\'),
            IFNULL(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.city\')), \'\'),
            IFNULL(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.state\')), \'\'),
            IFNULL(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.country\')), \'\')
        )))';
    }

    /**
     * Longest significant comma segment (or longest word) used as a loose anchor with radius.
     */
    protected static function primaryLocationAnchorNeedle(string $locationTrim): ?string
    {
        $parts = self::significantLocationSegments($locationTrim);
        if ($parts !== []) {
            usort($parts, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $best = mb_strtolower(trim($parts[0]));
            if (mb_strlen($best) >= 4) {
                return $best;
            }
            $joined = mb_strtolower(trim(preg_replace('/\s*,\s*/u', ' ', $locationTrim) ?? ''));
            if ($joined !== '' && $joined !== $best) {
                return $joined;
            }

            return $best !== '' ? $best : null;
        }
        $flat = mb_strtolower(trim(preg_replace('/\s*,\s*/u', ' ', $locationTrim) ?? ''));
        if ($flat === '') {
            return null;
        }
        $words = preg_split('/\s+/u', $flat, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $longest = '';
        foreach ($words as $w) {
            if (mb_strlen($w) > mb_strlen($longest)) {
                $longest = $w;
            }
        }
        if (mb_strlen($longest) >= 3) {
            return $longest;
        }

        return $flat;
    }

    /**
     * Within search radius AND (primary place token appears in location text OR stored location text is sparse).
     */
    protected static function applyWithinRadiusAnchorOrSparseToQuery(
        Builder $query,
        string $locationTrim,
        string $haversineSql,
        array $bindings
    ): void {
        $blob = self::sqlLocationTextBlobLower();
        $lenExpr = self::sqlLocationConcatTrimCharLength();
        $anchor = self::primaryLocationAnchorNeedle($locationTrim);
        $query->whereRaw($haversineSql, $bindings);
        $query->where(function (Builder $b) use ($blob, $lenExpr, $anchor) {
            if ($anchor !== null && $anchor !== '') {
                $like = '%'.addcslashes($anchor, '%_\\').'%';
                $b->whereRaw("({$blob}) LIKE ?", [$like]);
            }
            $b->orWhereRaw("({$lenExpr}) < 12");
        });
    }

    /**
     * Apply strict locality text: AND across significant comma segments on concatenated location fields.
     */
    protected static function applyLocationTextStrictToQuery(Builder $query, string $locationTrim): void
    {
        $blob = self::sqlLocationTextBlobLower();
        $segments = self::significantLocationSegments($locationTrim);
        if ($segments === []) {
            $needle = mb_strtolower($locationTrim);
            $like = '%'.addcslashes($needle, '%_\\').'%';
            $query->whereRaw("({$blob}) LIKE ?", [$like]);

            return;
        }
        foreach ($segments as $segment) {
            if (self::applyUsStateSegmentToQuery($query, $segment)) {
                continue;
            }
            $needles = self::segmentSearchNeedles($segment);
            if ($needles === []) {
                continue;
            }
            $query->where(function (Builder $q) use ($blob, $needles) {
                foreach ($needles as $n) {
                    $like = '%'.addcslashes($n, '%_\\').'%';
                    $q->orWhereRaw("({$blob}) LIKE ?", [$like]);
                }
            });
        }
    }

    /**
     * US state abbrev / full name: match JSON `state` or comma-bounded abbrev in blob.
     */
    protected static function applyUsStateSegmentToQuery(Builder $query, string $segment): bool
    {
        $t = trim($segment);
        if ($t === '') {
            return false;
        }
        $byAbbrev = self::usStateAbbrevToNameLower();
        $abbr = null;
        $full = null;
        if (mb_strlen($t) === 2 && ctype_alpha($t)) {
            $abbr = mb_strtoupper($t);
            if (! isset($byAbbrev[$abbr])) {
                return false;
            }
            $full = $byAbbrev[$abbr];
        } else {
            $needle = mb_strtolower($t);
            foreach ($byAbbrev as $a => $fn) {
                if ($needle === $fn) {
                    $abbr = $a;
                    $full = $fn;
                    break;
                }
            }
            if ($abbr === null) {
                return false;
            }
        }
        $a = mb_strtolower($abbr);
        $blob = self::sqlLocationTextBlobLower();
        $stateExpr = 'LOWER(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.state\')), \'\')))';
        $query->where(function (Builder $q) use ($stateExpr, $a, $full, $blob) {
            $q->whereRaw("({$stateExpr}) = ?", [$a])
                ->orWhereRaw("({$stateExpr}) = ?", [$full])
                ->orWhereRaw("({$stateExpr}) LIKE ?", ['%'.$full.'%']);
            foreach (['%, '.$a.',%', '%, '.$a.' %', '% '.$a.',%', '% '.$a.' %'] as $pat) {
                $q->orWhereRaw("({$blob}) LIKE ?", [$pat]);
            }
            $q->orWhereRaw("({$blob}) LIKE ?", ['%'.$full.'%']);
        });

        return true;
    }

    /**
     * Haversine distance (km) from search center to `additional_info.location` coordinates.
     * Supports `lat`/`long`, `latitude`/`longitude`, and `lng` (same shapes as listingMapper / Firestore).
     *
     * Bindings: centerLat, centerLng, centerLat, maxDistanceKm.
     */
    public static function sqlHaversineKmWithinRadiusOnAdditionalInfo(): string
    {
        $latRad = self::sqlAdditionalInfoLatitudeRadiansExpr();
        $lngRad = self::sqlAdditionalInfoLongitudeRadiansExpr();

        return "(6371 * acos(
            GREATEST(-1, LEAST(1,
                cos(radians(?)) * cos({$latRad})
                * cos({$lngRad} - radians(?))
                + sin(radians(?)) * sin({$latRad})
            ))
        )) <= ?";
    }

    protected static function sqlAdditionalInfoLatitudeRadiansExpr(): string
    {
        return 'radians(CAST(COALESCE(
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.lat\')), \'\'),
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.latitude\')), \'\')
        ) AS DECIMAL(12,8)))';
    }

    protected static function sqlAdditionalInfoLongitudeRadiansExpr(): string
    {
        return 'radians(CAST(COALESCE(
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.long\')), \'\'),
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.lng\')), \'\'),
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(additional_info, \'$.location.longitude\')), \'\')
        ) AS DECIMAL(12,8)))';
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
