<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConversationParticipant extends Model
{
    use HasFactory;

    /**
     * @var string[]
     */
    protected $fillable = [
        'conversation_id',
        'participant_id',
        'participant_type',
        'unread_count',
        'last_read_at'
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'last_read_at' => 'datetime',
    ];

    /**
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function participant()
    {
        return $this->morphTo();
    }
}
