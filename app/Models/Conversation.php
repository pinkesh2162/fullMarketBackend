<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model

{

    use HasFactory;

    /**
     * @var string[]
     */
    protected $fillable = ['last_message_id', 'last_message_at'];

    /**
     * @var string[]
     */
    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    /**
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function participants()
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /**
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    /**
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lastMessage()
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    /**
     * @param $actor
     *
     * @return Model|\Illuminate\Database\Eloquent\Relations\HasMany|object|null
     */
    public function otherParticipant($actor)
    {
        return $this->participants()
            ->where(function ($query) use ($actor) {
                $query->where('participant_id', '!=', $actor->id)
                    ->orWhere('participant_type', '!=', get_class($actor));
            })
            ->first();
    }
}
