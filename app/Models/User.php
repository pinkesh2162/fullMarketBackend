<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\CanInteractSocially;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class User extends Authenticatable implements HasMedia
{
    /** @use HasFactory<UserFactory> */
    use CanInteractSocially, HasApiTokens, HasFactory, InteractsWithMedia, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'unique_key',
        'firebase_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'phone_code',
        'location',
        'description',
        'password',
        'lang',
        'currency',
        'provider',
        'provider_id',
        'otp',
        'otp_expires_at',
        'email_verified_at',
        'last_login_at',
        'fcm_token',
        'data',
        'deleted_at',
        'role',
        'account_status',
        'registered_from',
    ];

    const PROFILE = 'user';

    public const ROLE_USER = 'user';

    public const ROLE_ADMIN = 'admin';

    public const REGISTERED_FROM_ANDROID = 'android';

    public const REGISTERED_FROM_IOS = 'ios';

    public const REGISTERED_FROM_WEB = 'web';

    /** @see \App\Repositories\Admin\AdminUserRepository for filters */
    public const ACCOUNT_STATUS_ACTIVE = 'active';

    public const ACCOUNT_STATUS_SUSPEND = 'suspend';

    public const ACCOUNT_STATUS_BLOCKED = 'blocked';

    public function publicAccountStatusForApi(): string
    {
        if ($this->trashed()) {
            return 'deleted';
        }

        $s = (string) ($this->getAttributes()['account_status'] ?? self::ACCOUNT_STATUS_ACTIVE);

        return in_array($s, [self::ACCOUNT_STATUS_ACTIVE, self::ACCOUNT_STATUS_SUSPEND, self::ACCOUNT_STATUS_BLOCKED], true)
            ? $s
            : self::ACCOUNT_STATUS_ACTIVE;
    }

    public function allowsAppLogin(): bool
    {
        if ($this->trashed()) {
            return false;
        }

        $s = (string) ($this->getAttributes()['account_status'] ?? self::ACCOUNT_STATUS_ACTIVE);

        return $s === self::ACCOUNT_STATUS_ACTIVE;
    }

    public function isAdminRole(): bool
    {
        return (string) ($this->getAttributes()['role'] ?? self::ROLE_USER) === self::ROLE_ADMIN;
    }

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
     * Include accessor URLs in API JSON (e.g. /contacts, profiles).
     *
     * @var list<string>
     */
    protected $appends = ['profile_photo'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    // protected function casts(): array
    // {
    //     return [
    //         'email_verified_at' => 'datetime',
    //         'password' => 'hashed',
    //         'location' => 'array',
    //     ];
    // }

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
        'location' => 'array',
        'data' => 'array',
    ];

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

        $fullName = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return getUserImageInitial($this->id, $fullName);
    }

    /**
     * Get the stores associated with the user.
     */
    public function store()
    {
        return $this->hasOne(Store::class);
    }

    /**
     * Get the stores associated with the user.
     */
    public function stores()
    {
        return $this->hasMany(Store::class);
    }

    /**
     * Get the favorite listings associated with the user.
     */
    public function favoriteListings()
    {
        return $this->belongsToMany(Listing::class, 'favorites', 'user_id', 'listing_id')
            ->using(Favorite::class)
            ->withPivot('deleted_at')
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
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

    /**
     * Get the notifications associated with the user.
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    // Relationships provided by CanInteractSocially:
    // sentFriendRequests, receivedFriendRequests, blockedEntities, blockedByEntities, conversations

    // Helper methods provided by CanInteractSocially:
    // hasBlocked, isBlockedBy, isFriendsWith, friends
}
