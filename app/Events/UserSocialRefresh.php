<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies one or more users to refresh inbox / social UI (lightweight push).
 * Used when a new chat message should update the conversation list for all participants.
 */
class UserSocialRefresh implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int>  $userIds
     */
    public function __construct(
        public array $userIds,
        public string $reason = 'message'
    ) {
        $this->userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    }

    public function broadcastAs(): string
    {
        return 'user.social.refresh';
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            fn (int $id) => new PrivateChannel('user.'.$id),
            $this->userIds
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['reason' => $this->reason];
    }
}
