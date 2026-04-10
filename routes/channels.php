<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('store.{id}', function ($user, $id) {
    $store = \App\Models\Store::find($id);
    return $store && (int) $store->user_id === (int) $user->id;
});

Broadcast::channel('chat.{id}', function ($user, $id) {
    // Check if user or any of their stores are participants
    $storeIds = $user->stores()->pluck('id')->toArray();
    
    return \App\Models\ConversationParticipant::where('conversation_id', $id)
        ->where(function ($q) use ($user, $storeIds) {
            $q->where(function ($sub) use ($user) {
                $sub->where('participant_id', $user->id)
                    ->where('participant_type', 'user');
            })->orWhere(function ($sub) use ($storeIds) {
                $sub->whereIn('participant_id', $storeIds)
                    ->where('participant_type', 'store');
            });
        })->exists();
});
