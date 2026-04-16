<?php

namespace App\Http\Controllers\Api\Firebase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SendTopicNotificationRequest;
use Illuminate\Http\JsonResponse;
use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Laravel\Firebase\Facades\Firebase;

class NotificationController extends Controller
{
    public function send(SendTopicNotificationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $message = CloudMessage::withTarget('topic', 'all_users')
                ->withNotification(Notification::create($validated['title'], $validated['body']));

            Firebase::messaging()->send($message);
        } catch (FirebaseException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification sent successfully',
        ]);
    }
}
