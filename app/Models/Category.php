<?php

namespace App\Models;

use App\Services\CategoryImageStorageService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Category extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'user_id',
        'name',
        'parent_id',
    ];

    const CATEGORY_IMAGE = 'category_image';

    /**
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * @return HasMany
     */
    public function subCategories(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * @return HasMany
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'service_category');
    }

    /**
     * Custom user categories → one default PNG. System categories → config map (per name,
     * including each subcategory) before seeded SVG media so listings show distinct raster icons.
     * If no map entry: uploaded media, then parent map, then global default.
     */
    public function getCategoryImageAttribute(): string
    {
        if ($this->user_id !== null) {
            return $this->resolvedConfigImageUrl('custom_category_image_url');
        }

        $map = config('categories.images_by_name', []);
        $storage = app(CategoryImageStorageService::class);
        $key = $this->normalizedCategoryNameKey((string) $this->name);

        $media = $this->getMedia(self::CATEGORY_IMAGE)->first();
        if ($media !== null && $media->mime_type !== 'image/svg+xml') {
            return $media->getFullUrl();
        }

        if (isset($map[$key])) {
            return $storage->resolveUrl($key, $map[$key]);
        }

        if ($media !== null) {
            return $media->getFullUrl();
        }

        if ($this->parent_id) {
            $parent = $this->relationLoaded('parent')
                ? $this->parent
                : $this->parent()->first();
            if ($parent !== null) {
                $pKey = $this->normalizedCategoryNameKey((string) $parent->name);
                if (isset($map[$pKey])) {
                    return $storage->resolveUrl($pKey, $map[$pKey]);
                }
            }
        }

        return $this->resolvedConfigImageUrl('default_image_url');
    }

    protected function normalizedCategoryNameKey(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));

        return Str::lower($name);
    }

    protected function resolvedConfigImageUrl(string $configKey): string
    {
        $storage = app(CategoryImageStorageService::class);
        $url = config('categories.'.$configKey);

        if ($configKey === 'custom_category_image_url') {
            return $storage->resolveUrlForKey('custom', is_string($url) ? $url : null);
        }

        if ($configKey === 'default_image_url') {
            return $storage->resolveUrlForKey('default', is_string($url) ? $url : null);
        }

        return $storage->resolveUrlForKey('default', config('categories.default_image_url'));
    }
}
