<?php

namespace App\Events;

use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    public function __construct(Message $message)
    {
        $this->message = $message->load('sender');
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.' . $this->message->conversation_id),
        ];
    }

    public function broadcastWith(): array
    {
        $sender = $this->message->sender;
        $senderName = $sender instanceof User ? ($sender->first_name . ' ' . $sender->last_name) : $sender->name;

        return [
            'message' => [
                'id' => $this->message->id,
                'conversation_id' => $this->message->conversation_id,
                'body' => $this->message->body,
                'type' => $this->message->type,
                'media_url' => $this->message->media_url,
                'created_at' => $this->message->created_at->toIso8601String(),
                'sender' => [
                    'id' => $sender->id,
                    'type' => get_class($sender),
                    'name' => $senderName,
                    'profile_photo' => $sender instanceof User ? $sender->profile_photo : $sender->profile_photo, // Both have this attribute
                ],
            ],
        ];
    }
}
