<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Events\UserSocialRefresh;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Store;
use App\Repositories\ChatRepository;
use App\Repositories\ContactRepository;
use App\Repositories\FriendRequestRepository;
use App\Services\SocketIoEmitter;
use Illuminate\Http\Request;

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
            'images' => 'nullable|array',
            'images.*' => 'file|image',
            'videos' => 'nullable|array',
            'videos.*' => 'file|mimes:mp4,mov,avi,wmv',
            'audios' => 'nullable|array',
            'audios.*' => 'file|mimes:mp3,wav,aac,m4a',
            'documents' => 'nullable|array',
            'documents.*' => 'file|mimes:pdf,doc,docx,xls,xlsx,txt',
        ]);

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
}
