<?php

namespace App\Events;

use App\Models\FriendRequest;
use App\Models\Store;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FriendRequestUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $friendRequest;

    public function __construct(FriendRequest $friendRequest)
    {
        $this->friendRequest = $friendRequest->load(['sender', 'receiver']);
    }

    /** Stable name for web / mobile Pusher and Socket.IO clients. */
    public function broadcastAs(): string
    {
        return 'friend_request.updated';
    }

    public function broadcastOn(): array
    {
        /**
         * Web/mobile clients subscribe to `private-user.{authUserId}` for social UI.
         * Requests to a **store** must also notify that store's owner on `user.{ownerId}` —
         * otherwise only `private-store.{storeId}` is used and the owner never hears Pusher.
         */
        $fr = $this->friendRequest;
        $names = [];
        $add = static function (string $type, $id) use (&$names): void {
            $key = $type.'.'.$id;
            $names[$key] = true;
        };

        $add($fr->sender_type, $fr->sender_id);
        $add($fr->receiver_type, $fr->receiver_id);

        $sender = $fr->sender;
        $receiver = $fr->receiver;
        if ($fr->sender_type === 'store' && $sender instanceof Store) {
            $add('user', $sender->user_id);
        }
        if ($fr->receiver_type === 'store' && $receiver instanceof Store) {
            $add('user', $receiver->user_id);
        }

        return array_map(
            static fn (string $name) => new PrivateChannel($name),
            array_keys($names)
        );
    }

    public function broadcastWith(): array
    {
        return [
            'friend_request' => [
                'id' => $this->friendRequest->id,
                'status' => $this->friendRequest->status,
                'sender' => [
                    'id' => $this->friendRequest->sender->id,
                    'type' => $this->friendRequest->sender_type,
                    'name' => $this->friendRequest->sender->social_name,
                    'profile_photo' => $this->friendRequest->sender->profile_photo,
                ],
                'receiver' => [
                    'id' => $this->friendRequest->receiver->id,
                    'type' => $this->friendRequest->receiver_type,
                    'name' => $this->friendRequest->receiver->social_name,
                    'profile_photo' => $this->friendRequest->receiver->profile_photo,
                ],
            ],
        ];
    }
}
