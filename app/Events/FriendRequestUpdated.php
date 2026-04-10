<?php

namespace App\Events;

use App\Models\FriendRequest;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
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

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel($this->friendRequest->receiver_type . '.' . $this->friendRequest->receiver_id),
            new PrivateChannel($this->friendRequest->sender_type . '.' . $this->friendRequest->sender_id),
        ];
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
