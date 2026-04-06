<?php

namespace App\Models;

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
        'store_id'
    ];

    //availability
    const AVAILABLE = true;
    const SOLD = false;

    //listing_type
    const FOR_SALE = 1;
    const FOR_RENT = 0;

    //listing service_type types
    const OFFER_SERVICE = 'offer_service';
    const ARTICLE_FOR_SALE = 'article_for_sale';
    const PROPERTY_FOR_SALE = 'property_for_sale';
    const VEHICLE_FOR_SALE = 'vehicle_for_sale';

    protected $casts = [
        'service_modality' => 'boolean',
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

    public function getImagesAttribute()
    {
        $media = $this->getMedia(self::LISTING_IMAGES);
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
            $q->where('search_keyword', 'like', "%{$search_keyword}%");
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
     * Category ids for listing filter: name or id match, plus all descendants (sub-categories).
     * Uses the query builder directly so a bad deploy on Category.php cannot break listing search.
     *
     * @return list<int>
     */
    protected static function categoryIdsForFilterIncludingDescendants(string|int $category): array
    {
        if (is_string($category) && is_numeric($category)) {
            $category = (int) $category;
        }

        if (is_int($category) || (is_string($category) && ctype_digit($category))) {
            $id = (int) $category;
            $rootIds = DB::table('categories')->where('id', $id)->pluck('id')->all();
        } else {
            $name = (string) $category;
            $like = '%'.addcslashes($name, '%_\\').'%';
            $rootIds = DB::table('categories')->where('name', 'like', $like)->pluck('id')->all();
        }

        if ($rootIds === []) {
            return [];
        }

        $rootIds = array_map('intval', $rootIds);
        $rows = DB::table('categories')->select('id', 'parent_id')->get();
        $childrenByParent = [];
        foreach ($rows as $row) {
            $pid = $row->parent_id;
            $key = $pid === null ? '_null_' : (int) $pid;
            if (! isset($childrenByParent[$key])) {
                $childrenByParent[$key] = [];
            }
            $childrenByParent[$key][] = (int) $row->id;
        }

        $result = [];
        $queue = array_values(array_unique($rootIds));
        $seen = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $result[] = $id;

            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $queue[] = $childId;
            }
        }

        return $result;
    }
}
