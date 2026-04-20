<?php

namespace App\Repositories;

use App\Exceptions\ApiOperationFailedException;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
//use App\Models\FriendRequest;
use App\Models\Message;
use App\Models\MessageParticipantHide;
use App\Models\Store;
use App\Models\User;
use Exception;
use Illuminate\Database\Eloquent\Model;
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
                ->with(['participants.participant'])
                ->orderBy('updated_at', 'desc')
                ->get()
                ->map(function ($conversation) use ($user, $storeIds) {
                    $me = $conversation->participants->where('participant_id', $user->id)->where('participant_type', 'user')->first()
                        ?? $conversation->participants->whereIn('participant_id', $storeIds)->where('participant_type', 'store')->first();

                    if (! $me) {
                        return null;
                    }

                    $other = $conversation->participants->where('id', '!=', $me->id)->first();
                    if (! $other || ! $other->participant) {
                        return null;
                    }

                    $participant = $other->participant;
                    $participant['participant_id'] = @$other->participant_id;
                    $participant['participant_type'] = @$other->participant_type;
                    $participant['name'] = @$other->participant->social_name;

                    $otherModel = $other->participant;
                    $meModel = $me->participant;
                    $iBlockedOther = false;
                    $otherBlockedMe = false;
                    if ($otherModel instanceof Model && $meModel instanceof Model) {
                        $iBlockedOther = $meModel->hasBlocked($otherModel);
                        $otherBlockedMe = $otherModel->hasBlocked($meModel);
                    }

                    return [
                        'id' => $conversation->id,
                        'participant' => $participant,
                        // [
                        //     'id' => $other->participant_id,
                        //     'type' => $other->participant_type,
                        //     'name' => $other->participant->social_name,
                        //     'profile_photo' => $other->participant->profile_photo,
                        // ],
                        /** Viewer identity in this thread (user row vs store). */
                        'self' => [
                            'id' => $me->participant_id,
                            'type' => $me->participant_type,
                            'name' => $me->participant->social_name,
                        ],
                        'last_message' => $this->lastVisibleMessagePayload($conversation, $me),
                        'unread_count' => $me->unread_count,
                        'updated_at' => $conversation->updated_at->toIso8601String(),
                        'i_blocked_other' => $iBlockedOther,
                        'other_blocked_me' => $otherBlockedMe,
                    ];
                })
                ->filter()
                ->values();

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
                ->visibleForParticipant((int) $me->participant_id, (string) $me->participant_type)
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
//            $this->assertParticipantsAreConnected(
//                (int) $data['sender_id'],
//                $senderType,
//                (int) $data['recipient_id'],
//                $recipientType
//            );

            $senderModel = $this->resolveParticipantEntity((int) $data['sender_id'], $senderType);
            $recipientModel = $this->resolveParticipantEntity((int) $data['recipient_id'], $recipientType);
            if (! $senderModel || ! $recipientModel) {
                throw new ApiOperationFailedException('Invalid chat participant', 400);
            }
            $this->assertNoBlockBetween($senderModel, $recipientModel);

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

                // 1. Handle text body (optional when media-only)
                $body = isset($data['body']) ? trim((string) $data['body']) : '';
                if ($body !== '') {
                    $messages->push(Message::create([
                        'conversation_id' => $conversation->id,
                        'sender_id' => $data['sender_id'],
                        'sender_type' => $senderType,
                        'body' => $body,
                        'type' => 'text',
                    ]));
                }

                // 2. Handle legacy single media
                $legacyMedia = $data['media'] ?? null;
                if ($legacyMedia instanceof \Illuminate\Http\UploadedFile) {
                    $type = $data['type'] ?? 'image';
                    $msg = Message::create([
                        'conversation_id' => $conversation->id,
                        'sender_id' => $data['sender_id'],
                        'sender_type' => $senderType,
                        'type' => $type,
                    ]);
                    $msg->addMedia($legacyMedia)->toMediaCollection('chat_media');
                    $messages->push($msg);
                }

                // 3. Handle media groups (single file or array — clients often send one file without a true PHP array)
                $mediaGroups = [
                    'images' => 'image',
                    'videos' => 'video',
                    'audios' => 'audio',
                    'documents' => 'document',
                ];

                foreach ($mediaGroups as $key => $type) {
                    $files = $this->normalizeUploadedFiles($data[$key] ?? null);
                    if ($files === []) {
                        continue;
                    }
                    $msg = Message::create([
                        'conversation_id' => $conversation->id,
                        'sender_id' => $data['sender_id'],
                        'sender_type' => $senderType,
                        'type' => $type,
                    ]);
                    foreach ($files as $file) {
                        $msg->addMedia($file)->toMediaCollection('chat_media');
                    }
                    $messages->push($msg);
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

    /**
     * @param  'for_me'|'for_everyone'  $scope
     * @return array{conversation_id: int, message_id: int, deleted_for_everyone: bool}
     *
     * @throws ApiOperationFailedException
     */
    public function deleteMessage(int $messageId, string $scope, int $actorId, string $actorType): array
    {
        try {
            $actorType = strtolower($actorType) === 'store' ? 'store' : 'user';

            /** @var User $auth */
            $auth = Auth::user();
            if (! $auth) {
                throw new ApiOperationFailedException('Unauthenticated', 401);
            }

            $this->assertSenderIdentityAllowed($auth, $actorId, $actorType);

            /** @var Message|null $message */
            $message = Message::query()->find($messageId);
            if (! $message) {
                throw new ApiOperationFailedException('Message not found', 404);
            }

            $conversation = Conversation::find($message->conversation_id);
            if (! $conversation) {
                throw new ApiOperationFailedException('Conversation not found', 404);
            }

            $me = $conversation->participants()
                ->where(function ($q) use ($actorId, $actorType) {
                    $q->where('participant_id', $actorId)->where('participant_type', $actorType);
                })->first();

            if (! $me) {
                throw new ApiOperationFailedException('You are not a participant in this conversation', 403);
            }

            if ($scope === 'for_me') {
                MessageParticipantHide::firstOrCreate([
                    'message_id' => $message->id,
                    'participant_id' => $me->participant_id,
                    'participant_type' => $me->participant_type,
                ]);

                return [
                    'conversation_id' => (int) $conversation->id,
                    'message_id' => (int) $message->id,
                    'deleted_for_everyone' => false,
                ];
            }

            if ($scope !== 'for_everyone') {
                throw new ApiOperationFailedException('Invalid delete scope', 400);
            }

            if ((int) $message->sender_id !== $actorId || (string) $message->sender_type !== $actorType) {
                throw new ApiOperationFailedException('Only the sender can delete this message for everyone', 403);
            }

            $cid = (int) $message->conversation_id;
            $mid = (int) $message->id;

            $message->update([
                'deleted_for_everyone_at' => now(),
                'deleted_for_everyone_by_id' => $actorId,
                'deleted_for_everyone_by_type' => $actorType,
                // Keep row visible, but clear content/media semantics.
                'body' => null,
                'type' => 'text',
            ]);

            return [
                'conversation_id' => $cid,
                'message_id' => $mid,
                'deleted_for_everyone' => true,
            ];
        } catch (ApiOperationFailedException $e) {
            throw $e;
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * Delete conversation only for current participant (WhatsApp-style "clear chat for me").
     * Receiver/other participants keep their full conversation/messages/media.
     * If no participants remain, hard-delete conversation + messages + media as cleanup.
     *
     * @return array{
     *   conversation_id:int,
     *   deleted_for_me:bool,
     *   deleted_globally:bool,
     *   messages_deleted:int,
     *   media_deleted:int
     * }
     *
     * @throws ApiOperationFailedException
     */
    public function deleteConversation(int $conversationId, int $actorId, string $actorType): array
    {
        try {
            $actorType = strtolower($actorType) === 'store' ? 'store' : 'user';

            /** @var User $auth */
            $auth = Auth::user();
            if (! $auth) {
                throw new ApiOperationFailedException('Unauthenticated', 401);
            }

            $this->assertSenderIdentityAllowed($auth, $actorId, $actorType);

            $conversation = Conversation::query()->find($conversationId);
            if (! $conversation) {
                throw new ApiOperationFailedException('Conversation not found', 404);
            }

            $isParticipant = $conversation->participants()
                ->where('participant_id', $actorId)
                ->where('participant_type', $actorType)
                ->exists();

            if (! $isParticipant) {
                throw new ApiOperationFailedException('You are not a participant in this conversation', 403);
            }

            return DB::transaction(function () use ($conversation, $actorId, $actorType): array {
                // Hide all messages for this actor only. Keep participants unchanged
                // so receiver side still sees normal conversation metadata.
                Message::withTrashed()
                    ->where('conversation_id', $conversation->id)
                    ->select('id')
                    ->orderBy('id')
                    ->chunkById(500, function ($messages) use ($actorId, $actorType): void {
                        foreach ($messages as $message) {
                            MessageParticipantHide::firstOrCreate([
                                'message_id' => (int) $message->id,
                                'participant_id' => $actorId,
                                'participant_type' => $actorType,
                            ]);
                        }
                    });

                $conversation->participants()
                    ->where('participant_id', $actorId)
                    ->where('participant_type', $actorType)
                    ->update([
                        'unread_count' => 0,
                        'last_read_at' => now(),
                    ]);

                return [
                    'conversation_id' => (int) $conversation->id,
                    'deleted_for_me' => true,
                    'deleted_globally' => false,
                    'messages_deleted' => 0,
                    'media_deleted' => 0,
                ];
            });
        } catch (ApiOperationFailedException $e) {
            throw $e;
        } catch (Exception $ex) {
            throw new ApiOperationFailedException($ex->getMessage(), (int) $ex->getCode());
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastVisibleMessagePayload(Conversation $conversation, ConversationParticipant $me): ?array
    {
        $last = Message::query()
            ->where('conversation_id', $conversation->id)
            ->visibleForParticipant((int) $me->participant_id, (string) $me->participant_type)
            ->orderByDesc('created_at')
            ->first();

        if (! $last) {
            return null;
        }

        return [
            'body' => $last->deleted_for_everyone_at ? 'This message was deleted' : $last->body,
            'type' => $last->type,
            'created_at' => $last->created_at->toIso8601String(),
            'deleted_for_everyone_at' => $last->deleted_for_everyone_at?->toIso8601String(),
        ];
    }

    private function refreshConversationLastMessage(Conversation $conversation): void
    {
        $last = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('created_at')
            ->first();

        $conversation->update([
            'last_message_id' => $last?->id,
            'last_message_at' => $last?->created_at ?? $conversation->last_message_at,
        ]);
    }

    /**
     * @return list<\Illuminate\Http\UploadedFile>
     */
    private function normalizeUploadedFiles(mixed $value): array
    {
        if ($value instanceof \Illuminate\Http\UploadedFile) {
            return $value->isValid() ? [$value] : [];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($f) => $f instanceof \Illuminate\Http\UploadedFile && $f->isValid()));
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
     * @return User|Store|null
     */
    private function resolveParticipantEntity(int $id, string $participantType): ?Model
    {
        $t = strtolower($participantType);
        if ($t === 'store') {
            return Store::query()->find($id);
        }

        return User::query()->find($id);
    }

    /**
     * @param  User|Store  $a
     * @param  User|Store  $b
     *
     * @throws ApiOperationFailedException
     */
    private function assertNoBlockBetween(Model $a, Model $b): void
    {
        if ($a->hasBlocked($b) || $b->hasBlocked($a)) {
            throw new ApiOperationFailedException(
                'Messaging is not available between these accounts.',
                403
            );
        }
    }

    /**
     * Messaging is only allowed for accepted friend/connection requests (user↔user, user↔store, store↔store).
     */
//    private function assertParticipantsAreConnected(int $aId, string $aType, int $bId, string $bType): void
//    {
//        $aAlias = $aType === 'store' ? 'store' : 'user';
//        $bAlias = $bType === 'store' ? 'store' : 'user';
//
//        $exists = FriendRequest::query()
//            ->where('status', 'accepted')
//            ->where(function ($q) use ($aId, $aAlias, $bId, $bAlias) {
//                $q->where(function ($q) use ($aId, $aAlias, $bId, $bAlias) {
//                    $q->where('sender_id', $aId)->where('sender_type', $aAlias)
//                        ->where('receiver_id', $bId)->where('receiver_type', $bAlias);
//                })->orWhere(function ($q) use ($aId, $aAlias, $bId, $bAlias) {
//                    $q->where('sender_id', $bId)->where('sender_type', $bAlias)
//                        ->where('receiver_id', $aId)->where('receiver_type', $aAlias);
//                });
//            })
//            ->exists();
//
//        if (! $exists) {
//            throw new ApiOperationFailedException('You can only message contacts you are connected with', 403);
//        }
//    }
}
