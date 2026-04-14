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
    public function sentFriendRequests(): MorphMany
    {
        return $this->morphMany(FriendRequest::class, 'sender');
    }

    public function receivedFriendRequests(): MorphMany
    {
        return $this->morphMany(FriendRequest::class, 'receiver');
    }

    public function blockedEntities(): MorphMany
    {
        return $this->morphMany(BlockedUser::class, 'blocker');
    }

    public function blockedByEntities(): MorphMany
    {
        return $this->morphMany(BlockedUser::class, 'blocked');
    }

    public function conversations(): MorphToMany
    {
        return $this->morphToMany(Conversation::class, 'participant', 'conversation_participants')
            ->using(ConversationParticipant::class)
            ->withPivot('unread_count', 'last_read_at')
            ->withTimestamps();
    }

    public function hasBlocked($entity): bool
    {
        $morph = $entity->getMorphClass();
        $class = get_class($entity);

        return $this->blockedEntities()
            ->where('blocked_id', $entity->id)
            ->where(function ($q) use ($morph, $class) {
                $q->where('blocked_type', $morph);
                if ($class !== $morph) {
                    $q->orWhere('blocked_type', $class);
                }
            })
            ->exists();
    }

    public function isBlockedBy($entity): bool
    {
        $morph = $entity->getMorphClass();
        $class = get_class($entity);

        return $this->blockedByEntities()
            ->where('blocker_id', $entity->id)
            ->where(function ($q) use ($morph, $class) {
                $q->where('blocker_type', $morph);
                if ($class !== $morph) {
                    $q->orWhere('blocker_type', $class);
                }
            })
            ->exists();
    }

    public function isFriendsWith($entity): bool
    {
        // (match1 OR match2) AND status — NOT match1 OR (match2 AND status)
        return FriendRequest::where(function ($q) use ($entity) {
            $q->where(function ($q) use ($entity) {
                $q->where('sender_id', $this->id)
                    ->where('sender_type', $this->getMorphClass())
                    ->where('receiver_id', $entity->id)
                    ->where('receiver_type', $entity->getMorphClass());
            })->orWhere(function ($q) use ($entity) {
                $q->where('sender_id', $entity->id)
                    ->where('sender_type', $entity->getMorphClass())
                    ->where('receiver_id', $this->id)
                    ->where('receiver_type', $this->getMorphClass());
            });
        })->where('status', 'accepted')->exists();
    }

    /**
     * @return mixed
     */
    public function friends()
    {
        $type = $this->getMorphClass();
        $id = $this->id;

        /**
         * Must group (sender-side OR receiver-side) AND status = accepted.
         * A bare where()->orWhere()->where('status') becomes A OR (B AND status) in SQL
         * and incorrectly includes pending rows where this entity is the sender.
         */
        return FriendRequest::query()
            ->where(function ($q) use ($id, $type) {
                $q->where(function ($q) use ($id, $type) {
                    $q->where('sender_id', $id)->where('sender_type', $type);
                })->orWhere(function ($q) use ($id, $type) {
                    $q->where('receiver_id', $id)->where('receiver_type', $type);
                });
            })
            ->where('status', 'accepted')
            ->get()
            ->map(function ($request) use ($id, $type) {
                return ($request->sender_id == $id && $request->sender_type == $type)
                    ? $request->receiver
                    : $request->sender;
            });
    }

    /**
     * Get display name for the entity.
     */
    public function getSocialNameAttribute(): string
    {
        if (isset($this->first_name)) {
            return trim($this->first_name.' '.($this->last_name ?? ''));
        }

        return $this->name ?? 'Unknown';
    }
}
