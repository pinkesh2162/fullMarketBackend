<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
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

        $query->when($filters['category'] ?? false, function ($q, $category) {
            $q->whereHas('category', function ($query) use ($category) {
                $query->where('name', 'like', "%{$category}%");
            });
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
}
