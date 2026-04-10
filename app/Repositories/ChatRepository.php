<?php

namespace App\Repositories;

use App\Exceptions\ApiOperationFailedException;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\Store;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

class ChatRepository extends BaseRepository
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
        return Conversation::class;
    }

    /**
     *
     * @throws ApiOperationFailedException
     *
     * @return mixed
     */
    public function getUserConversations()
    {
        try {
            $user = auth()->user();
            $storeIds = $user->stores()->pluck('id')->toArray();

            $conversation = Conversation::whereHas('participants', function ($query) use ($user, $storeIds) {
                $query->where(function ($q) use ($user) {
                    $q->where('participant_id', $user->id)
                        ->where('participant_type', 'user');
                })->orWhere(function ($q) use ($storeIds) {
                    $q->whereIn('participant_id', $storeIds)
                        ->where('participant_type', 'store');
                });
            })
                ->with(['participants.participant', 'lastMessage'])
                ->orderBy('updated_at', 'desc')
                ->get()
                ->map(function ($conversation) use ($user, $storeIds) {
                    $me = $conversation->participants->where('participant_id', $user->id)->where('participant_type', 'user')->first()
                        ?? $conversation->participants->whereIn('participant_id', $storeIds)->where('participant_type', 'store')->first();

                    $other = $conversation->participants->where('id', '!=', $me->id)->first();

                    return [
                        'id' => $conversation->id,
                        'participant' => [
                            'id' => $other->participant_id,
                            'type' => $other->participant_type,
                            'name' => $other->participant->social_name,
                            'profile_photo' => $other->participant->profile_photo,
                        ],
                        'last_message' => $conversation->lastMessage ? [
                            'body' => $conversation->lastMessage->body,
                            'type' => $conversation->lastMessage->type,
                            'created_at' => $conversation->lastMessage->created_at->toIso8601String(),
                        ] : null,
                        'unread_count' => $me->unread_count,
                        'updated_at' => $conversation->updated_at->toIso8601String(),
                    ];
                });

            return $conversation;
        } catch (Exception $ex) {
            // dd($ex->getMessage());
            throw new ApiOperationFailedException($ex->getMessage(), (int)$ex->getCode());
        }
    }

    /**
     * @param $conversationId
     *
     * @throws ApiOperationFailedException
     *
     * @return mixed
     */
    public function getConversationMessages($conversationId)
    {
        try {
            $user = auth()->user();
            $conversation = Conversation::find($conversationId);
            if (empty($conversation)) {
                throw new ApiOperationFailedException('Conversation not found', 404);
            }
            $storeIds = $user->stores()->pluck('id')->toArray();

            $me = $conversation->participants()
                ->where(function ($q) use ($user) {
                    $q->where('participant_id', $user->id)->where('participant_type', 'user');
                })->orWhere(function ($q) use ($storeIds) {
                    $q->whereIn('participant_id', $storeIds)->where('participant_type', 'store');
                })->firstOrFail();

            if ($me->unread_count > 0) {
                $me->update(['unread_count' => 0, 'last_read_at' => now()]);
            }

            return $conversation->messages()
                ->with('sender')
                ->orderBy('created_at', 'desc')
                ->paginate(30);
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int)$ex->getCode());
        }
    }

    /**
     * @param $data
     *
     * @throws ApiOperationFailedException
     *
     * @return mixed
     */
    public function sendMessage($data)
    {
        try {
            $senderType = strtolower($data['sender_type']);
            $recipientType = strtolower($data['recipient_type']);

            return DB::transaction(function () use ($data, $senderType, $recipientType) {
                $conversation = Conversation::whereHas('participants', function ($q) use ($data, $senderType) {
                    $q->where('participant_id', $data['sender_id'])->where('participant_type', $senderType);
                })->whereHas('participants', function ($q) use ($data, $recipientType) {
                    $q->where('participant_id', $data['recipient_id'])->where('participant_type', $recipientType);
                })->first();

                if (!$conversation) {
                    $conversation = Conversation::create();

                    $conversation->participants()->create([
                        'participant_id' => $data['sender_id'],
                        'participant_type' => $senderType,
                    ]);

                    $conversation->participants()->create([
                        'participant_id' => $data['recipient_id'],
                        'participant_type' => $recipientType,
                    ]);
                }

                $messages = collect();

                // 1. Handle text body
                if (!empty($data['body'])) {
                    $messages->push(Message::create([
                        'conversation_id' => $conversation->id,
                        'sender_id' => $data['sender_id'],
                        'sender_type' => $senderType,
                        'body' => $data['body'],
                        'type' => 'text',
                    ]));
                }

                // 2. Handle legacy single media
                if (isset($data['media']) && $data['media'] instanceof \Illuminate\Http\UploadedFile) {
                    $type = $data['type'] ?? 'image';
                    $msg = Message::create([
                        'conversation_id' => $conversation->id,
                        'sender_id' => $data['sender_id'],
                        'sender_type' => $senderType,
                        'type' => $type,
                    ]);
                    $msg->addMedia($data['media'])->toMediaCollection('chat_media');
                    $messages->push($msg);
                }

                // 3. Handle media arrays
                $mediaGroups = [
                    'images'    => 'image',
                    'videos'    => 'video',
                    'audios'    => 'audio',
                    'documents' => 'document'
                ];

                foreach ($mediaGroups as $key => $type) {
                    if (!empty($data[$key]) && is_array($data[$key])) {
                        // Create one message record per group (e.g. all 12 images in one message)
                        $msg = Message::create([
                            'conversation_id' => $conversation->id,
                            'sender_id' => $data['sender_id'],
                            'sender_type' => $senderType,
                            'type' => $type,
                        ]);
                        foreach ($data[$key] as $file) {
                            if ($file instanceof \Illuminate\Http\UploadedFile) {
                                $msg->addMedia($file)->toMediaCollection('chat_media');
                            }
                        }
                        $messages->push($msg);
                    }
                }

                if ($messages->isEmpty()) {
                    throw new ApiOperationFailedException('Message body or media is required', 400);
                }

                $lastMessage = $messages->last();

                $conversation->update([
                    'last_message_id' => $lastMessage->id,
                    'last_message_at' => now(),
                ]);

                $conversation->participants()
                    ->where('participant_id', $data['recipient_id'])
                    ->where('participant_type', $recipientType)
                    ->increment('unread_count');

                return $messages;
            });
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int)$ex->getCode());
        }
    }

    /**
     *
     * @throws ApiOperationFailedException
     *
     * @return int
     */
    public function getUnreadCount()
    {
        try {
            $user = auth()->user();
            $storeIds = $user->stores()->pluck('id')->toArray();

            return (int) ConversationParticipant::where(function ($q) use ($user) {
                $q->where('participant_id', $user->id)->where('participant_type', 'user');
            })->orWhere(function ($q) use ($storeIds) {
                $q->whereIn('participant_id', $storeIds)->where('participant_type', 'store');
            })->sum('unread_count');
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int)$ex->getCode());
        }
    }
}
