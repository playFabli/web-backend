<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Notification;

class NotificationController extends Controller
{
    /**
     * List the authenticated user's notifications (newest first).
     */
    public function index()
    {
        $user = app('token_user');

        $notifications = Notification::with('fromUser:id,username')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        $unread = Notification::unreadCountFor($user->id);

        return response()->json([
            'data' => $notifications,
            'unread_count' => $unread,
        ]);
    }

    /**
     * Unread count for a nav badge.
     */
    public function unreadCount()
    {
        $user = app('token_user');

        $unread = Notification::unreadCountFor($user->id);

        return response()->json([
            'data' => [
                'unread_count' => $unread,
            ],
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead($id)
    {
        $user = app('token_user');

        $notification = Notification::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $notification) {
            return response()->json([
                'message' => 'Notification not found',
            ], 404);
        }

        if (! $notification->read_at) {
            $notification->read_at = now();
            $notification->save();

            Notification::forgetUnreadCount($user->id);
        }

        return response()->json($notification, 200);
    }

    /**
     * Mark all of the authenticated user's notifications as read.
     */
    public function markAllRead()
    {
        $user = app('token_user');

        Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        Notification::forgetUnreadCount($user->id);

        return response()->json([], 200);
    }
}
