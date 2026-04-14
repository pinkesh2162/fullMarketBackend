<?php

namespace App\Services;

use App\Events\FriendRequestUpdated;
use App\Events\MessageDeleted;
use App\Events\MessageSent;
use App\Models\FriendRequest;
use App\Models\Message;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushes the same payload as {@see MessageSent} to the Socket.IO relay (HTTP).
 * Set SOCKET_IO_EMIT_URL and SOCKET_IO_EMIT_SECRET in .env, or this is a no-op.
 */
class SocketIoEmitter
{
    /**
     * Deliver an event to Socket.IO clients that joined room `user:{id}` (see socketio-server).
     *
     * @param  array<int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public static function emitToUserIds(array $userIds, string $event, array $data): void
    {
        $url = rtrim((string) config('services.socket_io.emit_url'), '/');
        $secret = (string) config('services.socket_io.emit_secret');
        if ($url === '' || $secret === '') {
            return;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($ids === []) {
            return;
        }
        try {
            Http::timeout(3)
                ->withHeaders([
                    'X-Internal-Secret' => $secret,
                    'Accept' => 'application/json',
                ])
                ->post($url.'/internal/emit-user', [
                    'user_ids' => $ids,
                    'event' => $event,
                    'data' => $data,
                ]);
        } catch (\Throwable $e) {
            Log::debug('Socket.IO emit-user failed: '.$e->getMessage());
        }
    }

    /** Same payload as {@see FriendRequestUpdated} Pusher broadcast — for clients using Socket.IO only. */
    public static function emitFriendRequest(FriendRequest $friendRequest): void
    {
        $friendRequest->loadMissing(['sender', 'receiver']);
        $payload = (new FriendRequestUpdated($friendRequest))->broadcastWith();
        $userIds = [];
        foreach (['sender', 'receiver'] as $role) {
            $type = $friendRequest->{$role.'_type'};
            $id = (int) $friendRequest->{$role.'_id'};
            if ($type === 'user') {
                $userIds[] = $id;
            } else {
                $model = $friendRequest->{$role};
                if ($model instanceof Store) {
                    $userIds[] = (int) $model->user_id;
                }
            }
        }
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === []) {
            return;
        }
        self::emitToUserIds($userIds, 'friend_request.updated', $payload);
    }

    public static function emit(Message $message): void
    {
        $url = rtrim((string) config('services.socket_io.emit_url'), '/');
        $secret = (string) config('services.socket_io.emit_secret');
        if ($url === '' || $secret === '') {
            return;
        }

        $message->loadMissing('sender');
        $event = new MessageSent($message);
        $data = $event->broadcastWith();

        try {
            Http::timeout(3)
                ->withHeaders([
                    'X-Internal-Secret' => $secret,
                    'Accept' => 'application/json',
                ])
                ->post($url.'/internal/emit', [
                    'conversation_id' => $message->conversation_id,
                    'event' => 'message.sent',
                    'data' => $data,
                ]);
        } catch (\Throwable $e) {
            Log::debug('Socket.IO emit failed: '.$e->getMessage());
        }
    }

    public static function emitMessageDeleted(int $messageId, int $conversationId, string $scope): void
    {
        $url = rtrim((string) config('services.socket_io.emit_url'), '/');
        $secret = (string) config('services.socket_io.emit_secret');
        if ($url === '' || $secret === '') {
            return;
        }

        $event = new MessageDeleted($messageId, $conversationId, $scope);
        $data = $event->broadcastWith();

        try {
            Http::timeout(3)
                ->withHeaders([
                    'X-Internal-Secret' => $secret,
                    'Accept' => 'application/json',
                ])
                ->post($url.'/internal/emit', [
                    'conversation_id' => $conversationId,
                    'event' => 'message.deleted',
                    'data' => $data,
                ]);
        } catch (\Throwable $e) {
            Log::debug('Socket.IO emit message.deleted failed: '.$e->getMessage());
        }
    }
}
