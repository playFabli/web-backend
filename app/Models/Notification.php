<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'from_user_id',
        'type',
        'title',
        'body',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Cached unread count for a user. The cache is reset whenever a new
     * notification is created or one is marked as read.
     */
    public static function unreadCountFor(int $userId): int
    {
        return (int) Cache::remember(
            "notification_unread_count:{$userId}",
            now()->addDay(),
            fn () => self::where('user_id', $userId)->whereNull('read_at')->count()
        );
    }

    /**
     * Drop the cached unread count for a user.
     */
    public static function forgetUnreadCount(int $userId): void
    {
        Cache::forget("notification_unread_count:{$userId}");
    }

    /**
     * Create a notification for a single recipient.
     */
    public static function send(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?int $fromUserId = null,
        ?array $data = null
    ): self {
        $notification = self::create([
            'user_id' => $userId,
            'from_user_id' => $fromUserId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        self::forgetUnreadCount($userId);

        return $notification;
    }

    /**
     * Create a notification for every active user (e.g. a new admin item).
     */
    public static function sendToAll(
        string $type,
        string $title,
        ?string $body = null,
        ?int $fromUserId = null,
        ?array $data = null
    ): void {
        User::query()
            ->where('role', '!=', 'banned')
            ->select('id')
            ->chunkById(500, function ($users) use ($type, $title, $body, $fromUserId, $data) {
                $now = now();
                $rows = $users->map(fn ($user) => [
                    'user_id' => $user->id,
                    'from_user_id' => $fromUserId,
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data !== null ? json_encode($data) : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('notifications')->insert($rows);

                $users->each(fn ($user) => self::forgetUnreadCount($user->id));
            });
    }
}
