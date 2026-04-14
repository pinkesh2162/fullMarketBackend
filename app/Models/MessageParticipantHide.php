<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MessageParticipantHide extends Model
{
    protected $table = 'message_participant_hides';

    /**
     * @var string[]
     */
    protected $fillable = [
        'message_id',
        'participant_id',
        'participant_type',
    ];

    /**
     * @return BelongsTo<Message, MessageParticipantHide>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function participant(): MorphTo
    {
        return $this->morphTo();
    }
}
