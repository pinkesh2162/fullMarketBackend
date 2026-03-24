<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Store extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\StoreFactory> */
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'user_id',
        'name',
        'location',
        'business_time',
        'contact_information',
        'social_media',
    ];

    protected $casts = [
        'business_time' => 'array',
        'contact_information' => 'array',
        'social_media' => 'array',
    ];

    const COVER_PHOTO = 'cover_photo';
    const PROFILE_PHOTO = 'profile_photo';
    
    /**
     * Get the user that owns the store.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getCoverPhotoAttribute()
    {
        /** @var Media $media */
        $media = $this->getMedia(self::COVER_PHOTO)->first();
        if (! empty($media)) {
            return $media->getFullUrl();
        }

        return getUserImageInitial($this->id, $this->name);
    }

    public function getProfilePhotoAttribute()
    {
        /** @var Media $media */
        $media = $this->getMedia(self::PROFILE_PHOTO)->first();
        if (! empty($media)) {
            return $media->getFullUrl();
        }

        return getUserImageInitial($this->id, $this->name);
    }
    
}
