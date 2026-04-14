<?php

namespace App\Models;

use App\Traits\CanInteractSocially;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Store extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\StoreFactory> */
    use CanInteractSocially, HasFactory, InteractsWithMedia;

    protected $fillable = [
        'unique_key',
        'user_id',
        'name',
        'location',
        'business_time',
        'contact_information',
        'social_media',
    ];

    protected $casts = [
        'location' => 'array',
        'business_time' => 'array',
        'contact_information' => 'array',
        'social_media' => 'array',
    ];

    /**
     * Include profile image URL in API JSON (e.g. /contacts).
     *
     * @var list<string>
     */
    protected $appends = ['profile_photo'];

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

    /**
     * Get the users that follow the store.
     */
    public function followers()
    {
        return $this->belongsToMany(User::class, 'follows', 'store_id', 'user_id')->withTimestamps();
    }

    /**
     * Get the ratings for the store.
     */
    public function ratings()
    {
        return $this->hasMany(Rating::class);
    }

    public function getAverageRatingAttribute()
    {
        return (float) $this->ratings()->avg('rating') ?: 0;
    }

    public function getRatingsCountAttribute()
    {
        return $this->ratings()->count();
    }

    // Relationships provided by CanInteractSocially:
    // sentFriendRequests, receivedFriendRequests, blockedEntities, blockedByEntities, conversations
}
