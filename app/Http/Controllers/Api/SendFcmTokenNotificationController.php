<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SendFcmTokenNotificationRequest;
use Illuminate\Http\JsonResponse;
use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Laravel\Firebase\Facades\Firebase;

class SendFcmTokenNotificationController extends Controller
{
    public function __invoke(SendFcmTokenNotificationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $message = CloudMessage::withTarget('token', $validated['fcm_token'])
                ->withNotification(Notification::create($validated['title'], $validated['body']));

            $fcmResult = Firebase::messaging()->send($message);
        } catch (FirebaseException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 502);
        }

        $messageId = is_array($fcmResult) ? ($fcmResult['name'] ?? null) : null;

        return response()->json([
            'success' => true,
            'message' => 'Notification sent successfully',
            'message_id' => $messageId,
        ]);
    }
}
