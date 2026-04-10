<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Message extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    /**
     * @var string[]
     */
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'sender_type',
        'body',
        'type',
        'read_at'
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'read_at' => 'datetime',
    ];

    protected $appends = ['media_url', 'all_media_urls'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function sender()
    {
        return $this->morphTo();
    }

    /**
     * @return string|null
     */
    public function getMediaUrlAttribute()
    {
        $media = $this->getFirstMedia('chat_media');

        return $media ? $media->getFullUrl() : null;
    }

    /**
     * @return array
     */
    public function getAllMediaUrlsAttribute()
    {
        return $this->getMedia('chat_media')->map(function ($media) {
            return $media->getFullUrl();
        })->toArray();
    }
}
