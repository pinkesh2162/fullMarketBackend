<?php

namespace App\Repositories;

use App\Exceptions\ApiOperationFailedException;
use App\Jobs\SendFcmNotificationJob;
use App\Models\FriendRequest;
use App\Models\Store;
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
     * @param  string  $type
     * @return mixed
     *
     * @throws ApiOperationFailedException
     */
    public function getReceivedRequests($id = null, $type = null)
    {
        try {
            /** @var User $user */
            $user = auth()->user();
            if ($type) {
                $entity = $this->resolveEntity($id ?? $user->id, $type);
                if (! $entity) {
                    throw new ApiOperationFailedException('Entity not found', 404);
                }

                return $entity->receivedFriendRequests()
                    ->where('status', 'pending')
                    ->with('sender')
                    ->get();
            }

            // Combined: User + all Store rows owned by this user (hasMany + hasOne fallback)
            $storeIds = $this->ownedStoreIds($user);

            return FriendRequest::query()
                ->where(function ($q) use ($user, $storeIds) {
                    $q->where(function ($q) use ($user) {
                        $q->where('receiver_id', $user->id)
                            ->where('receiver_type', 'user');
                    })->orWhere(function ($q) use ($storeIds) {
                        $q->whereIn('receiver_id', $storeIds)
                            ->where('receiver_type', 'store');
                    });
                })
                ->where('status', 'pending')
                ->with(['sender', 'receiver'])
                ->get();
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * @param  string  $type
     * @return mixed
     *
     * @throws ApiOperationFailedException
     */
    public function getSentRequests($id = null, $type = null)
    {
        try {
            /** @var User $user */
            $user = auth()->user();
            if ($type) {
                $entity = $this->resolveEntity($id ?? $user->id, $type);
                if (! $entity) {
                    throw new ApiOperationFailedException('Entity not found', 404);
                }

                return $entity->sentFriendRequests()
                    ->where('status', 'pending')
                    ->with('receiver')
                    ->get();
            }

            // Combined: User + all Store rows owned by this user (hasMany + hasOne fallback)
            $storeIds = $this->ownedStoreIds($user);

            return FriendRequest::query()
                ->where(function ($q) use ($user, $storeIds) {
                    $q->where(function ($q) use ($user) {
                        $q->where('sender_id', $user->id)
                            ->where('sender_type', 'user');
                    })->orWhere(function ($q) use ($storeIds) {
                        $q->whereIn('sender_id', $storeIds)
                            ->where('sender_type', 'store');
                    });
                })
                ->where('status', 'pending')
                ->with(['sender', 'receiver'])
                ->get();
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * Pending requests to the current user or to any store they own (same scope as getReceivedRequests).
     *
     * @throws ApiOperationFailedException
     */
    public function countPendingReceived(): int
    {
        try {
            /** @var User $user */
            $user = auth()->user();
            $storeIds = $this->ownedStoreIds($user);

            return (int) FriendRequest::query()
                ->where(function ($q) use ($user, $storeIds) {
                    $q->where(function ($q) use ($user) {
                        $q->where('receiver_id', $user->id)
                            ->where('receiver_type', 'user');
                    })->orWhere(function ($q) use ($storeIds) {
                        $q->whereIn('receiver_id', $storeIds)
                            ->where('receiver_type', 'store');
                    });
                })
                ->where('status', 'pending')
                ->count();
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * Pending requests from the current user or from any store they own (same scope as getSentRequests).
     *
     * @throws ApiOperationFailedException
     */
    public function countPendingSent(): int
    {
        try {
            /** @var User $user */
            $user = auth()->user();
            $storeIds = $this->ownedStoreIds($user);

            return (int) FriendRequest::query()
                ->where(function ($q) use ($user, $storeIds) {
                    $q->where(function ($q) use ($user) {
                        $q->where('sender_id', $user->id)
                            ->where('sender_type', 'user');
                    })->orWhere(function ($q) use ($storeIds) {
                        $q->whereIn('sender_id', $storeIds)
                            ->where('sender_type', 'store');
                    });
                })
                ->where('status', 'pending')
                ->count();
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * @param  string  $senderType
     * @param  string  $receiverType
     * @return mixed
     *
     * @throws ApiOperationFailedException
     */
    public function sendRequest($senderId, $receiverId, $senderType = 'user', $receiverType = 'user')
    {
        try {
            $sender = $this->resolveEntity($senderId, $senderType);
            $receiver = $this->resolveEntity($receiverId, $receiverType);

            if (! $sender || ! $receiver) {
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

            $alreadyFriends = FriendRequest::query()
                ->where(function ($q) use ($senderId, $senderAlias, $receiverId, $receiverAlias) {
                    $q->where(function ($q) use ($senderId, $senderAlias, $receiverId, $receiverAlias) {
                        $q->where('sender_id', $senderId)
                            ->where('sender_type', $senderAlias)
                            ->where('receiver_id', $receiverId)
                            ->where('receiver_type', $receiverAlias);
                    })->orWhere(function ($q) use ($senderId, $senderAlias, $receiverId, $receiverAlias) {
                        $q->where('sender_id', $receiverId)
                            ->where('sender_type', $receiverAlias)
                            ->where('receiver_id', $senderId)
                            ->where('receiver_type', $senderAlias);
                    });
                })
                ->where('status', 'accepted')
                ->exists();

            if ($alreadyFriends) {
                throw new ApiOperationFailedException('You are already friends or send request', 400);
            }

            // Check if request already sent (pending)
            $existingRequest = FriendRequest::query()
                ->where(function ($q) use ($senderId, $senderAlias, $receiverId, $receiverAlias) {
                    $q->where(function ($q) use ($senderId, $senderAlias, $receiverId, $receiverAlias) {
                        $q->where('sender_id', $senderId)
                            ->where('sender_type', $senderAlias)
                            ->where('receiver_id', $receiverId)
                            ->where('receiver_type', $receiverAlias);
                    })->orWhere(function ($q) use ($senderId, $senderAlias, $receiverId, $receiverAlias) {
                        $q->where('sender_id', $receiverId)
                            ->where('sender_type', $receiverAlias)
                            ->where('receiver_id', $senderId)
                            ->where('receiver_type', $senderAlias);
                    });
                })
                ->where('status', 'pending')
                ->first();

            if ($existingRequest) {
                if ($existingRequest->sender_id == $senderId && $existingRequest->sender_type == $senderAlias) {
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

            // FCM: notify store owner when receiver is a store
            $recipientUser = $receiver instanceof User ? $receiver : $receiver->user;
            if ($recipientUser && $recipientUser->fcm_token) {
                $title = 'New Friend Request';
                $body = "{$sender->social_name} sent you a friend request.";
                dispatch_sync(new SendFcmNotificationJob(
                    $recipientUser->fcm_token,
                    $title,
                    $body,
                    ['friend_request_id' => $friendRequest->id, 'type' => 'friend_request'],
                    $recipientUser->id
                ));
            }

            return $friendRequest;
        } catch (ApiOperationFailedException $e) {
            throw $e;
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * @return mixed
     *
     * @throws ApiOperationFailedException
     */
    public function updateStatus($requestId, $receiverId, $status, $receiverType = 'user')
    {
        try {
            $receiverTypeKey = $this->normalizeMorphType($receiverType);
            $friendRequest = FriendRequest::where('receiver_id', $receiverId)
                ->where('receiver_type', $receiverTypeKey)
                ->where('status', 'pending')
                ->find($requestId);

            if (! $friendRequest) {
                throw new ApiOperationFailedException('Friend request not found', 404);
            }

            $friendRequest->update(['status' => $status]);

            $friendRequest->refresh();
            $friendRequest->load(['sender', 'receiver']);
            if ($status === 'accepted') {
                $this->ensureUserUserBridgeForAcceptedMorphRequest($friendRequest);
            }

            // Send FCM notification back to sender
            $sender = $friendRequest->sender;
            $receiver = $friendRequest->receiver;
            if ($sender && $sender->fcm_token) {
                $title = $status == 'accepted' ? 'Friend Request Accepted' : 'Friend Request Rejected';
                $body = "{$receiver->social_name} ".($status == 'accepted' ? 'accepted' : 'rejected').' your friend request.';
                dispatch_sync(new SendFcmNotificationJob($sender->fcm_token, $title, $body, ['friend_request_id' => $friendRequest->id, 'type' => 'friend_request_'.$status], $sender->id));
            }

            return $friendRequest;
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * @return mixed
     *
     * @throws ApiOperationFailedException
     */
    public function cancelRequest($requestId, $senderId, $senderType = 'user')
    {
        try {
            $senderTypeKey = $this->normalizeMorphType($senderType);
            $friendRequest = FriendRequest::where('sender_id', $senderId)
                ->where('sender_type', $senderTypeKey)
                ->where('status', 'pending')
                ->find($requestId);

            if (! $friendRequest) {
                throw new ApiOperationFailedException('Friend request not found', 404);
            }

            $friendRequest->update(['status' => 'cancelled']);

            // Send FCM notification to receiver
            $receiver = $friendRequest->receiver;
            $sender = $friendRequest->sender;
            if ($receiver && $receiver->fcm_token) {
                $title = 'Friend Request Cancelled';
                $body = "{$sender->social_name} cancelled the friend request.";
                dispatch_sync(new SendFcmNotificationJob($receiver->fcm_token, $title, $body, ['friend_request_id' => $friendRequest->id, 'type' => 'friend_request_cancelled'], $receiver->id));
            }

            return $friendRequest;
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * So people can DM as users after a user↔store (or store↔store) connection, per product rules.
     */
    private function ensureUserUserBridgeForAcceptedMorphRequest(FriendRequest $fr): void
    {
        $fr->loadMissing(['sender', 'receiver']);

        if ($fr->sender_type === 'user' && $fr->receiver_type === 'store' && $fr->receiver instanceof Store) {
            $this->ensureAcceptedUserPair((int) $fr->sender_id, (int) $fr->receiver->user_id);

            return;
        }

        if ($fr->sender_type === 'store' && $fr->receiver_type === 'user' && $fr->sender instanceof Store) {
            $this->ensureAcceptedUserPair((int) $fr->sender->user_id, (int) $fr->receiver_id);

            return;
        }

        if ($fr->sender_type === 'store' && $fr->receiver_type === 'store'
            && $fr->sender instanceof Store && $fr->receiver instanceof Store) {
            $this->ensureAcceptedUserPair((int) $fr->sender->user_id, (int) $fr->receiver->user_id);
        }
    }

    private function ensureAcceptedUserPair(int $userIdA, int $userIdB): void
    {
        if ($userIdA === $userIdB) {
            return;
        }

        $exists = FriendRequest::where('status', 'accepted')
            ->where(function ($q) use ($userIdA, $userIdB) {
                $q->where(function ($q) use ($userIdA, $userIdB) {
                    $q->where('sender_id', $userIdA)->where('sender_type', 'user')
                        ->where('receiver_id', $userIdB)->where('receiver_type', 'user');
                })->orWhere(function ($q) use ($userIdA, $userIdB) {
                    $q->where('sender_id', $userIdB)->where('sender_type', 'user')
                        ->where('receiver_id', $userIdA)->where('receiver_type', 'user');
                });
            })->exists();

        if ($exists) {
            return;
        }

        FriendRequest::create([
            'sender_id' => $userIdA,
            'sender_type' => 'user',
            'receiver_id' => $userIdB,
            'receiver_type' => 'user',
            'status' => 'accepted',
        ]);
    }

    /**
     * @param  mixed  $type  'user'|'store' or legacy class name
     */
    private function normalizeMorphType($type): string
    {
        if ($type === 'user' || $type === 'store') {
            return $type;
        }
        if ($type === User::class || $type === 'App\\Models\\User') {
            return 'user';
        }
        if ($type === Store::class || $type === 'App\\Models\\Store') {
            return 'store';
        }

        $map = \Illuminate\Database\Eloquent\Relations\Relation::morphMap();
        $found = is_string($type) ? array_search($type, $map, true) : false;

        return is_string($found) ? $found : 'user';
    }

    /**
     * @return mixed
     */
    private function resolveEntity($id, $type)
    {
        $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($type) ?? $type;
        if (! class_exists($class)) {
            return null;
        }

        return $class::find($id);
    }

    /**
     * @return list<int|string>
     */
    private function ownedStoreIds(User $user): array
    {
        $ids = $user->stores()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $one = $user->store;
        if ($one && ! in_array((int) $one->id, $ids, true)) {
            $ids[] = (int) $one->id;
        }

        return array_values(array_unique($ids));
    }
}
