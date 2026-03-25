<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Category extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'user_id',
        'name',
        'parent_id',
    ];

    const CATEGORY_IMAGE = 'category_image';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function subCategories(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

     public function getCategoryImageAttribute()
    {
        /** @var Media $media */
        $media = $this->getMedia(self::CATEGORY_IMAGE)->first();
        if (! empty($media)) {
            return $media->getFullUrl();
        }   

        return getUserImageInitial($this->id, $this->name);
    }
}
