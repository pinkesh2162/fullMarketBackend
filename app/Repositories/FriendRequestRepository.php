<?php

namespace App\Repositories;

use App\Exceptions\ApiOperationFailedException;
use App\Jobs\SendFcmNotificationJob;
use App\Models\FriendRequest;
use App\Models\User;
use Exception;

class FriendRequestRepository extends BaseRepository
{
    /**
     * @return array
     */
    public function getFieldsSearchable()
    {
        return [];
    }

    /**
     * @return string
     */
    public function model()
    {
        return FriendRequest::class;
    }

    /**
     * @param $id
     * @param string $type
     * 
     * @throws ApiOperationFailedException
     *
     * @return mixed
     */
    public function getReceivedRequests($id = null, $type = null)
    {
        try {
            /** @var User $user */
            $user = auth()->user();
            if ($type) {
                $entity = $this->resolveEntity($id ?? $user->id, $type);
                if (!$entity) {
                    throw new ApiOperationFailedException('Entity not found', 404);
                }
                return $entity->receivedFriendRequests()
                    ->where('status', 'pending')
                    ->with('sender')
                    ->get();
            }

            // Combined: User + all their Stores
            $storeIds = $user->stores()->pluck('id')->toArray();

            return FriendRequest::where(function ($q) use ($user) {
                $q->where('receiver_id', $user->id)
                    ->where('receiver_type', 'user');
            })->orWhere(function ($q) use ($storeIds) {
                $q->whereIn('receiver_id', $storeIds)
                    ->where('receiver_type', 'store');
            })->where('status', 'pending')
                ->with(['sender', 'receiver'])
                ->get();
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int)$ex->getCode());
        }
    }

    /**
     * @param $id
     * @param string $type
     * 
     * @throws ApiOperationFailedException
     *
     * @return mixed
     */
    public function getSentRequests($id = null, $type = null)
    {
        try {
            /** @var User $user */
            $user = auth()->user();
            if ($type) {
                $entity = $this->resolveEntity($id ?? $user->id, $type);
                if (!$entity) {
                    throw new ApiOperationFailedException('Entity not found', 404);
                }
                return $entity->sentFriendRequests()
                    ->where('status', 'pending')
                    ->with('receiver')
                    ->get();
            }

            // Combined: User + all their Stores
            $storeIds = $user->stores()->pluck('id')->toArray();

            return FriendRequest::where(function ($q) use ($user) {
                $q->where('sender_id', $user->id)
                    ->where('sender_type', 'user');
            })->orWhere(function ($q) use ($storeIds) {
                $q->whereIn('sender_id', $storeIds)
                    ->where('sender_type', 'store');
            })->where('status', 'pending')
                ->with(['sender', 'receiver'])
                ->get();
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int)$ex->getCode());
        }
    }

    /**
     * @param $senderId
     * @param $receiverId
     * @param string $senderType
     * @param string $receiverType
     *
     * @throws ApiOperationFailedException
     *
     * @return mixed
     */
    public function sendRequest($senderId, $receiverId, $senderType = 'user', $receiverType = 'user')
    {
        try {
            $sender = $this->resolveEntity($senderId, $senderType);
            $receiver = $this->resolveEntity($receiverId, $receiverType);

            if (!$sender || !$receiver) {
                throw new ApiOperationFailedException('Entity not found', 404);
            }

            // Check if blocked
            if ($receiver->hasBlocked($sender)) {
                throw new ApiOperationFailedException('This entity has blocked you', 403);
            }
            if ($sender->hasBlocked($receiver)) {
                throw new ApiOperationFailedException('You have blocked this entity', 403);
            }

            // Check if already friends
            $morphMap = \Illuminate\Database\Eloquent\Relations\Relation::morphMap();
            $senderAlias = array_search($senderType, $morphMap) ?: $senderType;
            $receiverAlias = array_search($receiverType, $morphMap) ?: $receiverType;

            $alreadyFriends = FriendRequest::where(function ($q) use ($senderId, $senderAlias, $receiverId, $receiverAlias) {
                $q->where('sender_id', $senderId)
                    ->where('sender_type', $senderAlias)
                    ->where('receiver_id', $receiverId)
                    ->where('receiver_type', $receiverAlias);
            })->orWhere(function ($q) use ($senderId, $senderAlias, $receiverId, $receiverAlias) {
                $q->where('sender_id', $receiverId)
                    ->where('sender_type', $receiverAlias)
                    ->where('receiver_id', $senderId)
                    ->where('receiver_type', $senderAlias);
            })->where('status', 'accepted')->exists();

            if ($alreadyFriends) {
                throw new ApiOperationFailedException('You are already friends or send request', 400);
            }

            // Check if request already sent (pending)
            $existingRequest = FriendRequest::where(function ($q) use ($senderId, $senderAlias, $receiverId, $receiverAlias) {
                $q->where('sender_id', $senderId)
                    ->where('sender_type', $senderAlias)
                    ->where('receiver_id', $receiverId)
                    ->where('receiver_type', $receiverAlias);
            })->orWhere(function ($q) use ($senderId, $senderAlias, $receiverId, $receiverAlias) {
                $q->where('sender_id', $receiverId)
                    ->where('sender_type', $receiverAlias)
                    ->where('receiver_id', $senderId)
                    ->where('receiver_type', $senderAlias);
            })->where('status', 'pending')->first();

            if ($existingRequest) {
                if ($existingRequest->sender_id == $senderId && $existingRequest->sender_type == $senderType) {
                    throw new ApiOperationFailedException('Request already sent', 400);
                } else {
                    throw new ApiOperationFailedException('You already have a pending request from this entity', 400);
                }
            }

            $friendRequest = FriendRequest::create([
                'sender_id' => $senderId,
                'sender_type' => $senderAlias,
                'receiver_id' => $receiverId,
                'receiver_type' => $receiverAlias,
                'status' => 'pending',
            ]);

            // Send FCM notification to receiver
            if ($receiver->fcm_token) {
                $title = "New Friend Request";
                $body = "{$sender->social_name} sent you a friend request.";
                dispatch_sync(new SendFcmNotificationJob($receiver->fcm_token, $title, $body, ['friend_request_id' => $friendRequest->id, 'type' => 'friend_request'], $receiver->id));
            }

            return $friendRequest;
        } catch (ApiOperationFailedException $e) {
            throw $e;
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int)$ex->getCode());
        }
    }

    /**
     * @param $requestId
     * @param $receiverId
     * @param $status
     *
     * @throws ApiOperationFailedException
     *
     * @return mixed
     */
    public function updateStatus($requestId, $receiverId, $status, $receiverType = User::class)
    {
        try {
            $friendRequest = FriendRequest::where('receiver_id', $receiverId)
                ->where('receiver_type', $receiverType)
                ->where('status', 'pending')
                ->find($requestId);

            if (!$friendRequest) {
                throw new ApiOperationFailedException('Friend request not found', 404);
            }

            $friendRequest->update(['status' => $status]);

            // Send FCM notification back to sender
            $sender = $friendRequest->sender;
            $receiver = $friendRequest->receiver;
            if ($sender && $sender->fcm_token) {
                $title = $status == 'accepted' ? "Friend Request Accepted" : "Friend Request Rejected";
                $body = "{$receiver->social_name} " . ($status == 'accepted' ? "accepted" : "rejected") . " your friend request.";
                dispatch_sync(new SendFcmNotificationJob($sender->fcm_token, $title, $body, ['friend_request_id' => $friendRequest->id, 'type' => 'friend_request_' . $status], $sender->id));
            }

            return $friendRequest;
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int)$ex->getCode());
        }
    }

    /**
     * @param $requestId
     * @param $senderId
     *
     * @throws ApiOperationFailedException
     *
     * @return mixed
     */
    public function cancelRequest($requestId, $senderId, $senderType = User::class)
    {
        try {
            $friendRequest = FriendRequest::where('sender_id', $senderId)
                ->where('sender_type', $senderType)
                ->where('status', 'pending')
                ->find($requestId);

            if (!$friendRequest) {
                throw new ApiOperationFailedException('Friend request not found', 404);
            }

            $friendRequest->update(['status' => 'cancelled']);

            // Send FCM notification to receiver
            $receiver = $friendRequest->receiver;
            $sender = $friendRequest->sender;
            if ($receiver && $receiver->fcm_token) {
                $title = "Friend Request Cancelled";
                $body = "{$sender->social_name} cancelled the friend request.";
                dispatch_sync(new SendFcmNotificationJob($receiver->fcm_token, $title, $body, ['friend_request_id' => $friendRequest->id, 'type' => 'friend_request_cancelled'], $receiver->id));
            }

            return $friendRequest;
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int)$ex->getCode());
        }
    }
    /**
     * @param $id
     * @param $type
     * @return mixed
     */
    private function resolveEntity($id, $type)
    {
        $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($type) ?? $type;
        if (!class_exists($class)) {
            return null;
        }
        return $class::find($id);
    }
}
