<?php

namespace App\Http\Controllers\Api;

use App\Events\FriendRequestUpdated;
use App\Events\UserSocialRefresh;
use App\Exceptions\ApiOperationFailedException;
use App\Http\Controllers\Controller;
use App\Models\FriendRequest;
use App\Models\Store;
use App\Models\User;
use App\Repositories\FriendRequestRepository;
use App\Services\SocketIoEmitter;
use Illuminate\Http\Request;

class FriendRequestController extends Controller
{
    /**
     * @var FriendRequestRepository
     */
    protected $friendRequestRepository;

    /**
     * FriendRequestController constructor.
     */
    public function __construct(FriendRequestRepository $friendRequestRepo)
    {
        $this->friendRequestRepository = $friendRequestRepo;
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws ApiOperationFailedException
     */
    public function getReceivedRequests(Request $request)
    {
        $id = $request->query('receiver_id');
        $type = $request->query('receiver_type');

        // If store is explicitly requested, verify ownership
        if ($type == 'store' && $id) {
            $store = \App\Models\Store::find($id);
            if (! $store || $store->user_id != auth()->id()) {
                return $this->actionFailure('Unauthorized store access', null, 403);
            }
        }

        $requests = $this->friendRequestRepository->getReceivedRequests($id, $type);

        return $this->actionSuccess('Received requests retrieved successfully', $requests);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws ApiOperationFailedException
     */
    public function getSentRequests(Request $request)
    {
        $id = $request->query('sender_id');
        $type = $request->query('sender_type');

        // If store is explicitly requested, verify ownership
        if ($type == 'store' && $id) {
            $store = \App\Models\Store::find($id);
            if (! $store || $store->user_id != auth()->id()) {
                return $this->actionFailure('Unauthorized store access', null, 403);
            }
        }

        $requests = $this->friendRequestRepository->getSentRequests($id, $type);

        return $this->actionSuccess('Sent requests retrieved successfully', $requests);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendRequest(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required|numeric',
            'receiver_type' => 'nullable|in:user,store',
            'sender_id' => 'nullable|numeric',
            'sender_type' => 'nullable|in:user,store',
        ]);

        $senderId = $request->sender_id ?? auth()->id();
        $senderType = $request->sender_type ?? 'user';
        $receiverId = $request->receiver_id;
        $receiverType = $request->receiver_type ?? 'user';

        // Check if sender is same as receiver
        if ($senderId == $receiverId && $senderType == $receiverType) {
            return $this->actionFailure('You cannot send a friend request to yourself', null, 400);
        }

        // If sender is store, verify ownership
        if ($senderType == 'store') {
            $store = \App\Models\Store::find($senderId);
            if (! $store || $store->user_id != auth()->id()) {
                return $this->actionFailure('Unauthorized store access', null, 403);
            }
        }

        try {
            $friendRequest = $this->friendRequestRepository->sendRequest($senderId, $receiverId, $senderType, $receiverType);

            broadcast(new FriendRequestUpdated($friendRequest))->toOthers();
            SocketIoEmitter::emitFriendRequest($friendRequest);
            $this->broadcastSocialRefreshForFriendRequest($friendRequest);

            return $this->actionSuccess('Friend request sent successfully', $friendRequest);
        } catch (ApiOperationFailedException $e) {
            return $this->actionFailure($e->getMessage(), null, $e->getCode());
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function respondToRequest(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:accepted,rejected',
            'receiver_id' => 'nullable|numeric',
            'receiver_type' => 'nullable|in:user,store',
        ]);

        $receiverId = $request->receiver_id ?? auth()->id();
        $receiverType = $request->receiver_type ?? 'user';

        // If receiver is store, verify ownership
        if ($receiverType == 'store') {
            $store = \App\Models\Store::find($receiverId);
            if (! $store || $store->user_id != auth()->id()) {
                return $this->actionFailure('Unauthorized store access', null, 403);
            }
        }

        try {
            $friendRequest = $this->friendRequestRepository->updateStatus($id, $receiverId, $request->status, $receiverType);

            broadcast(new FriendRequestUpdated($friendRequest))->toOthers();
            SocketIoEmitter::emitFriendRequest($friendRequest);
            $this->broadcastSocialRefreshForFriendRequest($friendRequest);

            return $this->actionSuccess('Friend request '.$request->status.' successfully', $friendRequest);
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelRequest(Request $request, $id)
    {
        $senderId = $request->query('sender_id', auth()->id());
        $senderType = $request->query('sender_type', 'user');

        // If sender is store, verify ownership
        if ($senderType == 'store') {
            $store = \App\Models\Store::find($senderId);
            if (! $store || $store->user_id != auth()->id()) {
                return $this->actionFailure('Unauthorized store access', null, 403);
            }
        }

        try {
            $this->friendRequestRepository->cancelRequest($id, $senderId, $senderType);

            return $this->actionSuccess('Friend request cancelled successfully');
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * Push lightweight inbox refresh so Contacts / Sent / Received lists update without full reload.
     */
    private function broadcastSocialRefreshForFriendRequest(FriendRequest $friendRequest): void
    {
        $friendRequest->loadMissing(['sender', 'receiver']);
        $userIds = [];
        foreach (['sender', 'receiver'] as $role) {
            $m = $friendRequest->{$role};
            if ($m instanceof User) {
                $userIds[] = (int) $m->id;
            } elseif ($m instanceof Store) {
                $userIds[] = (int) $m->user_id;
            }
        }
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === []) {
            return;
        }
        broadcast(new UserSocialRefresh($userIds, 'friend_request'));
        SocketIoEmitter::emitToUserIds($userIds, 'user.social.refresh', ['reason' => 'friend_request']);
    }
}
