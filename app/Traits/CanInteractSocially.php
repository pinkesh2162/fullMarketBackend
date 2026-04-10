<?php

namespace App\Traits;

use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\FriendRequest;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait CanInteractSocially
{
    /**
     * @return MorphMany
     */
    public function sentFriendRequests(): MorphMany
    {
        return $this->morphMany(FriendRequest::class, 'sender');
    }

    /**
     * @return MorphMany
     */
    public function receivedFriendRequests(): MorphMany
    {
        return $this->morphMany(FriendRequest::class, 'receiver');
    }

    /**
     * @return MorphMany
     */
    public function blockedEntities(): MorphMany
    {
        return $this->morphMany(BlockedUser::class, 'blocker');
    }

    /**
     * @return MorphMany
     */
    public function blockedByEntities(): MorphMany
    {
        return $this->morphMany(BlockedUser::class, 'blocked');
    }

    /**
     * @return MorphToMany
     */
    public function conversations(): MorphToMany
    {
        return $this->morphToMany(Conversation::class, 'participant', 'conversation_participants')
            ->using(ConversationParticipant::class)
            ->withPivot('unread_count', 'last_read_at')
            ->withTimestamps();
    }

    /**
     * @param $entity
     * @return bool
     */
    public function hasBlocked($entity): bool
    {
        return $this->blockedEntities()
            ->where('blocked_id', $entity->id)
            ->where('blocked_type', $entity->getMorphClass())
            ->exists();
    }

    /**
     * @param $entity
     * @return bool
     */
    public function isBlockedBy($entity): bool
    {
        return $this->blockedByEntities()
            ->where('blocker_id', $entity->id)
            ->where('blocker_type', $entity->getMorphClass())
            ->exists();
    }

    /**
     * @param $entity
     * @return bool
     */
    public function isFriendsWith($entity): bool
    {
        return FriendRequest::where(function ($q) use ($entity) {
            $q->where('sender_id', $this->id)
                ->where('sender_type', $this->getMorphClass())
                ->where('receiver_id', $entity->id)
                ->where('receiver_type', $entity->getMorphClass());
        })->orWhere(function ($q) use ($entity) {
            $q->where('sender_id', $entity->id)
                ->where('sender_type', $entity->getMorphClass())
                ->where('receiver_id', $this->id)
                ->where('receiver_type', $this->getMorphClass());
        })->where('status', 'accepted')->exists();
    }

    /**
     * @return mixed
     */
    public function friends()
    {
        $type = $this->getMorphClass();
        $id = $this->id;

        return FriendRequest::where(function ($q) use ($id, $type) {
            $q->where('sender_id', $id)
                ->where('sender_type', $type);
        })->orWhere(function ($q) use ($id, $type) {
            $q->where('receiver_id', $id)
                ->where('receiver_type', $type);
        })->where('status', 'accepted')
            ->get()
            ->map(function ($request) use ($id, $type) {
                return ($request->sender_id == $id && $request->sender_type == $type)
                    ? $request->receiver
                    : $request->sender;
            });
    }

    /**
     * Get display name for the entity.
     * 
     * @return string
     */
    public function getSocialNameAttribute(): string
    {
        if (isset($this->first_name)) {
            return trim($this->first_name . ' ' . ($this->last_name ?? ''));
        }
        return $this->name ?? 'Unknown';
    }
}
