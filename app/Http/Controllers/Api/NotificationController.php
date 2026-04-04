<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Get the authenticated user's notifications.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('perPage', 15);
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate($perPage);

        return $this->actionSuccess(
            'notifications_fetched',
            NotificationResource::collection($notifications),
            self::HTTP_OK,
            $this->customizingResponseData($notifications)['pagination']
        );
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead($id): JsonResponse
    {
        $notification = auth()->user()->notifications()->findOrFail($id);
        $notification->update(['read_at' => now()]);

        return $this->actionSuccess('notification_marked_as_read');
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(): JsonResponse
    {
        auth()->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return $this->actionSuccess('all_notifications_marked_as_read');
    }

    /**
     * Delete a notification.
     */
    public function destroy($id): JsonResponse
    {
        $notification = auth()->user()->notifications()->findOrFail($id);
        $notification->delete();

        return $this->actionSuccess('notification_deleted');
    }

    /**
     * Delete a notification.
     */
    public function destroyAssign(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array',
        ]);

        $ids = array_map('intval', $request->ids);
        auth()->user()->notifications()->whereIn('id', $ids)->delete();

        return $this->actionSuccess('notifications_deleted');
    }

    /**
     * Delete all notifications for the authenticated user.
     */
    public function deleteAll(): JsonResponse
    {
        auth()->user()->notifications()->delete();

        return $this->actionSuccess('all_notifications_deleted');
    }
}
