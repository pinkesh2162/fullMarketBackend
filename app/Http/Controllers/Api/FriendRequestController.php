<?php

namespace App\Http\Controllers\Api;

use App\Events\FriendRequestUpdated;
use App\Exceptions\ApiOperationFailedException;
use App\Http\Controllers\Controller;
use App\Repositories\FriendRequestRepository;
use Illuminate\Http\Request;

class FriendRequestController extends Controller
{
    /**
     * @var FriendRequestRepository
     */
    protected $friendRequestRepository;

    /**
     * FriendRequestController constructor.
     * @param  FriendRequestRepository  $friendRequestRepo
     */
    public function __construct(FriendRequestRepository $friendRequestRepo)
    {
        $this->friendRequestRepository = $friendRequestRepo;
    }

    /**
     * @param  Request  $request
     * @throws ApiOperationFailedException
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getReceivedRequests(Request $request)
    {
        $id = $request->query('receiver_id');
        $type = $request->query('receiver_type');

        // If store is explicitly requested, verify ownership
        if ($type == 'store' && $id) {
            $store = \App\Models\Store::find($id);
            if (!$store || $store->user_id != auth()->id()) {
                return $this->actionFailure('Unauthorized store access', null, 403);
            }
        }

        $requests = $this->friendRequestRepository->getReceivedRequests($id, $type);

        return $this->actionSuccess('Received requests retrieved successfully', $requests);
    }

    /**
     * @param  Request  $request
     * @throws ApiOperationFailedException
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSentRequests(Request $request)
    {
        $id = $request->query('sender_id');
        $type = $request->query('sender_type');

        // If store is explicitly requested, verify ownership
        if ($type == 'store' && $id) {
            $store = \App\Models\Store::find($id);
            if (!$store || $store->user_id != auth()->id()) {
                return $this->actionFailure('Unauthorized store access', null, 403);
            }
        }

        $requests = $this->friendRequestRepository->getSentRequests($id, $type);

        return $this->actionSuccess('Sent requests retrieved successfully', $requests);
    }

    /**
     * @param  Request  $request
     *
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
            if (!$store || $store->user_id != auth()->id()) {
                return $this->actionFailure('Unauthorized store access', null, 403);
            }
        }

        try {
            $friendRequest = $this->friendRequestRepository->sendRequest($senderId, $receiverId, $senderType, $receiverType);

            broadcast(new FriendRequestUpdated($friendRequest))->toOthers();

            return $this->actionSuccess('Friend request sent successfully', $friendRequest);
        } catch (ApiOperationFailedException $e) {
            return $this->actionFailure($e->getMessage(), null, $e->getCode());
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * @param  Request  $request
     * @param $id
     *
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
            if (!$store || $store->user_id != auth()->id()) {
                return $this->actionFailure('Unauthorized store access', null, 403);
            }
        }

        try {
            $friendRequest = $this->friendRequestRepository->updateStatus($id, $receiverId, $request->status, $receiverType);

            broadcast(new FriendRequestUpdated($friendRequest))->toOthers();

            return $this->actionSuccess('Friend request ' . $request->status . ' successfully', $friendRequest);
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * @param $id
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelRequest(Request $request, $id)
    {
        $senderId = $request->query('sender_id', auth()->id());
        $senderType = $request->query('sender_type', 'user');

        // If sender is store, verify ownership
        if ($senderType == 'store') {
            $store = \App\Models\Store::find($senderId);
            if (!$store || $store->user_id != auth()->id()) {
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
}
