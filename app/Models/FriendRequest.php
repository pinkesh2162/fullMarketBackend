<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FriendRequest extends Model
{
    use HasFactory;

    /**
     * @var string[]
     */
    protected $fillable = ['sender_id', 'sender_type', 'receiver_id', 'receiver_type', 'status'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function sender()
    {
        return $this->morphTo();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function receiver()
    {
        return $this->morphTo();
    }
}
