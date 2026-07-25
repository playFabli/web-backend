<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserFriend;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class FriendController extends Controller
{
    public function friends($id)
    {
        $user = User::select(['id'])->where('id', $id)->first();
        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $page = request()->query('page', 1);
        $perPage = 20;

        $cacheKey = "user:{$id}:friends:page:{$page}";

        $data = Cache::remember($cacheKey, 60, function () use ($id, $page, $perPage) {
            $friendIds = UserFriend::where('first_id', $id)
                ->pluck('second_id')
                ->merge(
                    UserFriend::where('second_id', $id)->pluck('first_id')
                );

            $total = $friendIds->count();

            $paginatedIds = $friendIds->forPage($page, $perPage);

            $friends = User::select(['id', 'username', 'bubble', 'last_seen_at'])
                ->whereIn('id', $paginatedIds)
                ->get()
                ->keyBy('id');

            $items = $paginatedIds->map(fn ($friendId) => $friends->get($friendId))
                ->filter()
                ->values()
                ->toArray();

            return [
                'items' => $items,
                'total' => $total,
            ];
        });

        $paginated = new LengthAwarePaginator(
            $data['items'],
            $data['total'],
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return response()->json($paginated);
    }
}
