<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class UserFriendRequest extends Model
{
    /**
     * Cached count of pending friend requests for a user. The cache is reset
     * whenever a request is sent to or resolved by that user.
     */
    public static function pendingCountFor(int $userId): int
    {
        return (int) Cache::remember(
            "friend_request_pending_count:{$userId}",
            now()->addDay(),
            fn () => self::where('to_id', $userId)->count()
        );
    }

    /**
     * Drop the cached pending count for a user.
     */
    public static function forgetPendingCount(int $userId): void
    {
        Cache::forget("friend_request_pending_count:{$userId}");
    }

    public function from()
    {
        return $this->belongsTo(User::class, 'from_id');
    }

    public function to()
    {
        return $this->belongsTo(User::class, 'to_id');
    }
}
