<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class User extends Authenticatable implements HasMedia
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, InteractsWithMedia;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'phone_code',
        'location',
        'description',
        'password',
        'lang',
        'provider',
        'provider_id',
        'otp',
        'otp_expires_at',
        'email_verified_at'
    ];

    const PROFILE = 'user';

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'location'          => 'array'
        ];
    }

    public function getProfilePhotoAttribute()
    {
        /** @var Media $media */
        // $media = $this->getMedia(self::PROFILE)->first();
        $media = $this->media
            ->where('collection_name', self::PROFILE)
            ->first();

        if (! empty($media)) {
            return $media->getFullUrl();
        }

        $fullName = trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));

        return getUserImageInitial($this->id, $fullName);
    }

    /**
     * Get the store associated with the user.
     */
    public function store()
    {
        return $this->hasOne(Store::class);
    }

    /**
     * Get the favorite listings associated with the user.
     */
    public function favoriteListings()
    {
        return $this->belongsToMany(Listing::class, 'favorites', 'user_id', 'listing_id')->withTimestamps();
    }

    /**
     * Get the listings associated with the user.
     */
    public function listings()
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * Get the claims associated with the user.
     */
    public function claims()
    {
        return $this->hasMany(Claim::class);
    }
    /**
     * Get the stores followed by the user.
     */
    public function followedStores()
    {
        return $this->belongsToMany(Store::class, 'follows', 'user_id', 'store_id')->withTimestamps();
    }

    /**
     * Get the settings associated with the user.
     */
    public function settings()
    {
        return $this->hasOne(UserSetting::class);
    }
}
