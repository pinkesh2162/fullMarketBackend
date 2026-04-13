<?php

namespace App\Repositories;

use App\Exceptions\ApiOperationFailedException;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\FriendRequest;
use App\Models\Message;
use App\Models\Store;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
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
     * @return mixed
     *
     * @throws ApiOperationFailedException
     */
    public function getUserConversations()
    {
        try {
            $user = auth()->user();
            $storeIds = $user->stores()->pluck('id')->toArray();
            if ($user->store && ! in_array((int) $user->store->id, array_map('intval', $storeIds), true)) {
                $storeIds[] = $user->store->id;
            }

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
                        /** Viewer identity in this thread (user row vs store). */
                        'self' => [
                            'id' => $me->participant_id,
                            'type' => $me->participant_type,
                            'name' => $me->participant->social_name,
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
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * @return mixed
     *
     * @throws ApiOperationFailedException
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
            if ($user->store && ! in_array((int) $user->store->id, array_map('intval', $storeIds), true)) {
                $storeIds[] = $user->store->id;
            }

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
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * @return mixed
     *
     * @throws ApiOperationFailedException
     */
    public function sendMessage($data)
    {
        try {
            $senderType = strtolower($data['sender_type']);
            $recipientType = strtolower($data['recipient_type']);

            /** @var User $auth */
            $auth = Auth::user();
            if (! $auth) {
                throw new ApiOperationFailedException('Unauthenticated', 401);
            }

            $this->assertSenderIdentityAllowed($auth, (int) $data['sender_id'], $senderType);
            $this->assertParticipantsAreConnected(
                (int) $data['sender_id'],
                $senderType,
                (int) $data['recipient_id'],
                $recipientType
            );

            return DB::transaction(function () use ($data, $senderType, $recipientType) {
                $conversation = Conversation::whereHas('participants', function ($q) use ($data, $senderType) {
                    $q->where('participant_id', $data['sender_id'])->where('participant_type', $senderType);
                })->whereHas('participants', function ($q) use ($data, $recipientType) {
                    $q->where('participant_id', $data['recipient_id'])->where('participant_type', $recipientType);
                })->first();

                if (! $conversation) {
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
                if (! empty($data['body'])) {
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
                    'images' => 'image',
                    'videos' => 'video',
                    'audios' => 'audio',
                    'documents' => 'document',
                ];

                foreach ($mediaGroups as $key => $type) {
                    if (! empty($data[$key]) && is_array($data[$key])) {
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
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * @return int
     *
     * @throws ApiOperationFailedException
     */
    public function getUnreadCount()
    {
        try {
            $user = auth()->user();
            $storeIds = $user->stores()->pluck('id')->toArray();
            if ($user->store && ! in_array((int) $user->store->id, array_map('intval', $storeIds), true)) {
                $storeIds[] = $user->store->id;
            }

            return (int) ConversationParticipant::where(function ($q) use ($user) {
                $q->where('participant_id', $user->id)->where('participant_type', 'user');
            })->orWhere(function ($q) use ($storeIds) {
                $q->whereIn('participant_id', $storeIds)->where('participant_type', 'store');
            })->sum('unread_count');
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    private function assertSenderIdentityAllowed(User $auth, int $senderId, string $senderType): void
    {
        if ($senderType === 'user') {
            if ($senderId !== (int) $auth->id) {
                throw new ApiOperationFailedException('Unauthorized sender', 403);
            }

            return;
        }

        if ($senderType === 'store') {
            $store = Store::find($senderId);
            if (! $store || (int) $store->user_id !== (int) $auth->id) {
                throw new ApiOperationFailedException('Unauthorized store sender', 403);
            }

            return;
        }

        throw new ApiOperationFailedException('Invalid sender type', 400);
    }

    /**
     * Messaging is only allowed for accepted friend/connection requests (user↔user, user↔store, store↔store).
     */
    private function assertParticipantsAreConnected(int $aId, string $aType, int $bId, string $bType): void
    {
        $aAlias = $aType === 'store' ? 'store' : 'user';
        $bAlias = $bType === 'store' ? 'store' : 'user';

        $exists = FriendRequest::query()
            ->where('status', 'accepted')
            ->where(function ($q) use ($aId, $aAlias, $bId, $bAlias) {
                $q->where(function ($q) use ($aId, $aAlias, $bId, $bAlias) {
                    $q->where('sender_id', $aId)->where('sender_type', $aAlias)
                        ->where('receiver_id', $bId)->where('receiver_type', $bAlias);
                })->orWhere(function ($q) use ($aId, $aAlias, $bId, $bAlias) {
                    $q->where('sender_id', $bId)->where('sender_type', $bAlias)
                        ->where('receiver_id', $aId)->where('receiver_type', $aAlias);
                });
            })
            ->exists();

        if (! $exists) {
            throw new ApiOperationFailedException('You can only message contacts you are connected with', 403);
        }
    }
}
