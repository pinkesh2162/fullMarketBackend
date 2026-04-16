<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SendTopicNotificationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Laravel\Firebase\Facades\Firebase;

/**
 * Sends the same notification as {@see SendFcmTokenNotificationController} to each distinct
 * non-empty {@see User::$fcm_token} (trimmed). Uses per-token {@see Firebase::messaging()::send()}
 * so the FCM payload matches the known-good single-device route (multicast can behave differently
 * for some clients).
 */
class SendFcmToAllUsersController extends Controller
{
    private const USER_CHUNK = 250;

    public function __invoke(SendTopicNotificationRequest $request): JsonResponse
    {
        set_time_limit(0);

        $validated = $request->validated();
        $title = $validated['title'];
        $body = $validated['body'];

        $query = User::query()
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '');

        $usersWithToken = (int) (clone $query)->count();

        if ($usersWithToken === 0) {
            return response()->json([
                'success' => true,
                'message' => 'No users with FCM tokens in database',
                'users_with_token' => 0,
                'distinct_tokens' => 0,
                'tokens_targeted' => 0,
                'sent' => 0,
                'failed' => 0,
                'cleared_stale_tokens' => 0,
                'delivery_note' => 'No rows in users.fcm_token; apps must call POST /api/update-fcm-token after FCM gives a registration token.',
            ]);
        }

        $distinctTokens = (int) DB::table('users')
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->selectRaw('COUNT(DISTINCT fcm_token) as c')
            ->value('c');

        $sent = 0;
        $failed = 0;
        $tokensTargeted = 0;
        $clearedRows = 0;
        $seenTokens = [];

        $messaging = Firebase::messaging();

        try {
            $query->orderBy('id')->chunkById(self::USER_CHUNK, function ($users) use (
                $messaging,
                $title,
                $body,
                &$sent,
                &$failed,
                &$tokensTargeted,
                &$clearedRows,
                &$seenTokens
            ): void {
                foreach ($users as $user) {
                    $raw = $user->fcm_token;
                    if (! is_string($raw)) {
                        continue;
                    }

                    $token = trim($raw);
                    if ($token === '') {
                        continue;
                    }

                    if (isset($seenTokens[$token])) {
                        continue;
                    }
                    $seenTokens[$token] = true;

                    $tokensTargeted++;

                    $message = CloudMessage::withTarget('token', $token)
                        ->withNotification(Notification::create($title, $body));

                    try {
                        $messaging->send($message);
                        $sent++;
                    } catch (FirebaseException $e) {
                        $failed++;
                        if ($e instanceof NotFound) {
                            $clearedRows += User::query()
                                ->whereRaw('TRIM(COALESCE(fcm_token, "")) = ?', [$token])
                                ->update(['fcm_token' => null]);
                        }
                    }
                }
            });
        } catch (FirebaseException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'users_with_token' => $usersWithToken,
                'distinct_tokens' => $distinctTokens,
                'tokens_targeted' => $tokensTargeted,
                'sent' => $sent,
                'failed' => $failed,
                'cleared_stale_tokens' => $clearedRows,
            ], 502);
        }

        if ($clearedRows > 0) {
            Log::info('fcm.broadcast_cleared_unregistered_tokens', ['user_rows_updated' => $clearedRows]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification sent successfully',
            'users_with_token' => $usersWithToken,
            'distinct_tokens' => $distinctTokens,
            'tokens_targeted' => $tokensTargeted,
            'sent' => $sent,
            'failed' => $failed,
            'cleared_stale_tokens' => $clearedRows,
            'delivery_note' => 'Each send uses the same FCM path as POST /api/send-notification-to-token. If Postman works but DB users do not, the stored token usually differs (truncation, whitespace, or stale token) — run migration for TEXT column and ensure the app calls POST /api/update-fcm-token with the current registration token.',
        ]);
    }
}
