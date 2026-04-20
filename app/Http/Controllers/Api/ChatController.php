<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageDeleted;
use App\Events\MessageSent;
use App\Events\UserSocialRefresh;
use App\Exceptions\ApiOperationFailedException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Store;
use App\Repositories\ChatRepository;
use App\Repositories\ContactRepository;
use App\Repositories\FriendRequestRepository;
use App\Services\SocketIoEmitter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class ChatController extends Controller
{
    /**
     * @var ChatRepository
     */
    protected $chatRepository;

    protected ContactRepository $contactRepository;

    protected FriendRequestRepository $friendRequestRepository;

    /**
     * ChatController constructor.
     */
    public function __construct(
        ChatRepository $chatRepo,
        ContactRepository $contactRepository,
        FriendRequestRepository $friendRequestRepository
    ) {
        $this->chatRepository = $chatRepo;
        $this->contactRepository = $contactRepository;
        $this->friendRequestRepository = $friendRequestRepository;
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \App\Exceptions\ApiOperationFailedException
     */
    public function listConversations(Request $request)
    {
        $data = $this->chatRepository->getUserConversations();

        return $this->actionSuccess('Conversations retrieved successfully', $data);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \App\Exceptions\ApiOperationFailedException
     */
    public function getMessages(Request $request, $id)
    {
        $messages = $this->chatRepository->getConversationMessages($id);

        return $this->actionSuccess('Messages retrieved successfully', $this->customizingResponseData($messages));
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'sender_id' => 'required',
            'sender_type' => 'required|in:User,Store',
            'recipient_id' => 'required',
            'recipient_type' => 'required|in:User,Store',
            'body' => 'nullable|string',
            'type' => 'nullable|in:text,image,video,audio,document',
            'media' => 'nullable|file',
            'images' => 'nullable',
            'videos' => 'nullable',
            'audios' => 'nullable',
            'documents' => 'nullable',
        ]);

        $this->validateChatMediaFiles($request);

        try {
            $messages = $this->chatRepository->sendMessage($request->all());

            foreach ($messages as $message) {
                $loaded = $message->load('sender');
                broadcast(new MessageSent($loaded))->toOthers();
                SocketIoEmitter::emit($loaded);
            }

            $first = $messages->first();
            if ($first) {
                $conversation = Conversation::with(['participants'])->find($first->conversation_id);
                if ($conversation) {
                    $userIds = [];
                    foreach ($conversation->participants as $p) {
                        if ($p->participant_type === 'user') {
                            $userIds[] = (int) $p->participant_id;
                        } elseif ($p->participant_type === 'store') {
                            $store = Store::find($p->participant_id);
                            if ($store) {
                                $userIds[] = (int) $store->user_id;
                            }
                        }
                    }
                    $userIds = array_values(array_unique(array_filter($userIds)));
                    if ($userIds !== []) {
                        broadcast(new UserSocialRefresh($userIds, 'message'));
                        SocketIoEmitter::emitToUserIds($userIds, 'user.social.refresh', ['reason' => 'message']);
                    }
                }
            }

            return $this->actionSuccess('Messages sent successfully', $messages);
        } catch (ApiOperationFailedException $e) {
            $code = $e->getCode();
            if ($code < 400 || $code >= 600) {
                $code = 400;
            }

            return $this->actionFailure($e->getMessage(), $e->data, $code);
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * Mobile-friendly: start or continue a conversation with the seller of a listing by `listing_id` only.
     * Uses the same delivery pipeline as {@see sendMessage} — no friend/connection requirement.
     * Recipient is the listing's store (if `store_id` set) else the listing owner user.
     */
    public function sendMessageToSeller(Request $request)
    {
        $request->validate([
            'listing_id' => 'required|integer|exists:listings,id',
            'body' => 'nullable|string',
            'type' => 'nullable|in:text,image,video,audio,document',
            'media' => 'nullable|file',
            'images' => 'nullable',
            'videos' => 'nullable',
            'audios' => 'nullable',
            'documents' => 'nullable',
        ]);

        $this->validateChatMediaFiles($request);

        $listing = Listing::with('store')->findOrFail((int) $request->input('listing_id'));
        $user = $request->user();

        if ((int) $listing->user_id === (int) $user->id) {
            return $this->actionFailure('You cannot message your own listing', null, 400);
        }

        if ($listing->store_id) {
            $store = $listing->store ?? Store::find($listing->store_id);
            if ($store && (int) $store->user_id === (int) $user->id) {
                return $this->actionFailure('You cannot message your own store listing', null, 400);
            }
        }

        $request->merge([
            'sender_id' => (string) $user->id,
            'sender_type' => 'User',
            'recipient_id' => $listing->store_id
                ? (string) $listing->store_id
                : (string) $listing->user_id,
            'recipient_type' => $listing->store_id ? 'Store' : 'User',
        ]);

        return $this->sendMessage($request);
    }

    /**
     * Delete a chat message for the current participant only, or for all participants (sender only).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteMessage(Request $request, $id)
    {
        $request->validate([
            'scope' => 'required|in:for_me,for_everyone',
            'actor_id' => 'nullable|numeric',
            'actor_type' => 'nullable|in:User,Store',
        ]);

        $actorId = (int) ($request->input('actor_id') ?? auth()->id());
        $actorTypeRaw = $request->input('actor_type', 'User');
        $actorType = strtolower((string) $actorTypeRaw) === 'store' ? 'store' : 'user';

        try {
            $result = $this->chatRepository->deleteMessage(
                (int) $id,
                (string) $request->input('scope'),
                $actorId,
                $actorType
            );

            if ($result['deleted_for_everyone']) {
                broadcast(new MessageDeleted(
                    $result['message_id'],
                    $result['conversation_id'],
                    'for_everyone'
                ));
                SocketIoEmitter::emitMessageDeleted(
                    $result['message_id'],
                    $result['conversation_id'],
                    'for_everyone'
                );
            }

            return $this->actionSuccess(
                $result['deleted_for_everyone']
                    ? 'Message deleted for everyone'
                    : 'Message deleted for you',
                $result
            );
        } catch (\App\Exceptions\ApiOperationFailedException $e) {
            return $this->actionFailure($e->getMessage(), null, $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * Delete conversation for current actor only (other participant keeps their chat).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteConversation(Request $request, $id)
    {
        $request->validate([
            'actor_id' => 'nullable|numeric',
            'actor_type' => 'nullable|in:User,Store',
        ]);

        $actorId = (int) ($request->input('actor_id') ?? auth()->id());
        $actorTypeRaw = $request->input('actor_type', 'User');
        $actorType = strtolower((string) $actorTypeRaw) === 'store' ? 'store' : 'user';

        try {
            $result = $this->chatRepository->deleteConversation((int) $id, $actorId, $actorType);

            return $this->actionSuccess('Conversation deleted for you', $result);
        } catch (\App\Exceptions\ApiOperationFailedException $e) {
            return $this->actionFailure($e->getMessage(), null, $e->getCode() ?: 400);
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \App\Exceptions\ApiOperationFailedException
     */
    public function getUnreadCount()
    {
        $count = $this->chatRepository->getUnreadCount();

        return $this->actionSuccess('Unread count retrieved successfully', ['unread_count' => $count]);
    }

    /**
     * Single payload for chat tab badges: unread messages, contacts, pending friend requests.
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \App\Exceptions\ApiOperationFailedException
     */
    public function getChatTabCounts()
    {
        $user = auth()->user();
        $unread = $this->chatRepository->getUnreadCount();
        $contacts = $this->contactRepository->countContacts($user);
        $received = $this->friendRequestRepository->countPendingReceived();
        $sent = $this->friendRequestRepository->countPendingSent();

        return $this->actionSuccess('Chat tab counts retrieved successfully', [
            'unread_messages' => $unread,
            'contacts' => $contacts,
            'friend_requests_received' => $received,
            'friend_requests_sent' => $sent,
            'all_counts' => (int) $unread + (int) $contacts + (int) $received + (int) $sent,
        ]);
    }

    /**
     * Multipart may send a single file (not wrapped in an array) for keys like images[] — validate each file.
     */
    private function validateChatMediaFiles(Request $request): void
    {
        $perKeyRules = [
            'images' => ['file', 'image'],
            'videos' => ['file', 'mimes:mp4,mov,avi,wmv'],
            'audios' => ['file', 'mimes:mp3,wav,aac,m4a'],
            'documents' => ['file', 'mimes:pdf,doc,docx,xls,xlsx,txt'],
        ];

        foreach ($perKeyRules as $key => $rules) {
            $raw = $request->file($key);
            if ($raw === null) {
                continue;
            }
            $files = $raw instanceof UploadedFile ? [$raw] : (is_array($raw) ? $raw : []);
            foreach ($files as $i => $file) {
                if (! $file instanceof UploadedFile) {
                    continue;
                }
                Validator::make(
                    [$key.'_'.$i => $file],
                    [$key.'_'.$i => $rules]
                )->validate();
            }
        }
    }
}
