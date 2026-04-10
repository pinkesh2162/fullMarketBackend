<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Repositories\ChatRepository;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    /**
     * @var ChatRepository
     */
    protected $chatRepository;

    /**
     * ChatController constructor.
     * @param  ChatRepository  $chatRepo
     */
    public function __construct(ChatRepository $chatRepo)
    {
        $this->chatRepository = $chatRepo;
    }

    /**
     * @param  Request  $request
     *
     * @throws \App\Exceptions\ApiOperationFailedException
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function listConversations(Request $request)
    {
        $data = $this->chatRepository->getUserConversations();

        return $this->actionSuccess('Conversations retrieved successfully', $data);
    }

    /**
     * @param  Request  $request
     * @param $id
     *
     * @throws \App\Exceptions\ApiOperationFailedException
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMessages(Request $request, $id)
    {
        $messages = $this->chatRepository->getConversationMessages($id);

        return $this->actionSuccess('Messages retrieved successfully', $this->customizingResponseData($messages));
    }

    /**
     * @param  Request  $request
     *
     *
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
                broadcast(new MessageSent($message->load('sender')))->toOthers();
            }

            return $this->actionSuccess('Messages sent successfully', $messages);
        } catch (\Exception $e) {
            return $this->serverError($e->getMessage());
        }
    }

    /**
     *
     * @throws \App\Exceptions\ApiOperationFailedException
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUnreadCount()
    {
        $count = $this->chatRepository->getUnreadCount();
        return $this->actionSuccess('Unread count retrieved successfully', ['unread_count' => $count]);
    }
}
