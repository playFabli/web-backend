<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Helpers\PythonRenderHelper;
use App\Http\Requests\User\PostToWallRequest;
use App\Models\ActivityLog;
use App\Models\AdminLog;
use App\Models\AvatarPoseDefinition;
use App\Models\BlogPost;
use App\Models\Collection;
use App\Models\ForumTag;
use App\Models\ForumTagInventory;
use App\Models\ForumThread;
use App\Models\MarketplaceCaseContent;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceItemInventory;
use App\Models\Petition;
use App\Models\PetitionVote;
use App\Models\RoadmapItem;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserAvatarColor;
use App\Models\UserBan;
use App\Models\UserFriend;
use App\Models\UserFriendRequest;
use App\Models\UserPaymentContract;
use App\Models\UserProfileWall;
use App\Models\UserWearing;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class GeneralController extends Controller
{
    public function me()
    {
        $user = app('token_user');
        if ($user) {
            $hidden = method_exists($user, 'getHidden') ? $user->getHidden() : [];
            if (! empty($hidden)) {
                $user->makeVisible($hidden);
            }
        }

        $data = $user;
        $data['privacy'] = $user->privacy;

        // Include selected forum tag
        if ($user->selected_forum_tag_id) {
            $data['selected_forum_tag'] = ForumTag::find($user->selected_forum_tag_id);
        } else {
            $data['selected_forum_tag'] = null;
        }

        return response()->json([
            'data' => $data,
        ], 200);
    }

    public function banStatus()
    {
        $user = app('token_user');

        $latestBan = UserBan::select(['id', 'user_id', 'banned_by_admin_id', 'banned_for', 'banned_at', 'expires_at'])
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        $activeBan = UserBan::select(['id', 'user_id', 'banned_by_admin_id', 'banned_for', 'banned_at', 'expires_at'])
            ->where('user_id', $user->id)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->latest()
            ->first();

        if ($activeBan) {
            return response()->json([
                'data' => [
                    'is_banned' => true,
                    'ban' => [
                        'id' => $activeBan->id,
                        'reason' => $activeBan->banned_for,
                        'banned_at' => $activeBan->banned_at,
                        'expires_at' => $activeBan->expires_at,
                    ],
                ],
            ], 200);
        }

        if ($latestBan) {
            return response()->json([
                'data' => [
                    'is_banned' => true,
                    'ban' => [
                        'id' => $latestBan->id,
                        'reason' => $latestBan->banned_for,
                        'banned_at' => $latestBan->banned_at,
                        'expires_at' => $latestBan->expires_at,
                    ],
                ],
            ], 200);
        }

        return response()->json([
            'data' => [
                'is_banned' => false,
            ],
        ], 200);
    }

    public function unbanSelf()
    {
        $user = app('token_user');

        $latestBan = UserBan::where('user_id', $user->id)
            ->latest()
            ->first();

        if ($latestBan->expires_at == null || ! Carbon::parse($latestBan->expires_at)->isPast()) {
            return;
        }

        if ($latestBan) {
            $user->role = 'user';
            $user->save();

            $latestBan->delete();

            return response()->json([
                'data' => [
                    'message' => 'Account reinstated successfully',
                ],
            ], 200);
        }

        return response()->json([
            'data' => [
                'message' => 'No ban found',
            ],
        ], 200);
    }

    public function user($id)
    {
        $from = app('token_user');

        $cacheKey = 'user:'.$id.':friend_status';
        if ($from) {
            $fromId = is_object($from) ? $from->id : $from['id'];
            $cacheKey .= ':from:'.$fromId;
        } else {
            $cacheKey .= ':from:guest';
        }

        $user = Cache::remember($cacheKey, 60, function () use ($id) {
            return User::select([
                'id', 'username', 'description', 'bubble', 'level', 'exp', 'coins', 'profile_theme_id', 'avatar_frame_id', 'role',
                'final_rap', 'is_email_verified', 'last_seen_at', 'created_at',
            ])
                ->where('id', $id)
                ->with('privacy')
                ->first()->toArray();
        });

        if (! $from || ! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $user['friend_status'] = 'none';

        $fromId = is_object($from) ? $from->id : $from['id'];

        // Include first 5 friends
        $friendIds = UserFriend::where('first_id', $user['id'])
            ->pluck('second_id')
            ->merge(
                UserFriend::where('second_id', $user['id'])->pluck('first_id')
            )
            ->take(10);

        $user['friends'] = User::select(['id', 'username', 'bubble', 'last_seen_at'])
            ->whereIn('id', $friendIds)
            ->get()
            ->toArray();

        $user['friends_count'] = UserFriend::where('first_id', $user['id'])
            ->count() + UserFriend::where('second_id', $user['id'])->count();
        $request = UserFriendRequest::select(['id', 'from_id', 'to_id'])
            ->where('from_id', $fromId)->where('to_id', $user['id'])
            ->orWhere('from_id', $user['id'])->where('to_id', $fromId)
            ->first();
        if ($request) {
            if ($request->from_id == $fromId) {
                $user['friend_status'] = 'sent';
            } else {
                $user['friend_status'] = 'received';
            }
        } else {
            $friends = UserFriend::select(['id', 'first_id', 'second_id'])
                ->where('first_id', $fromId)->where('second_id', $user['id'])
                ->orWhere('first_id', $user['id'])->where('second_id', $fromId)
                ->first();
            if ($friends) {
                $user['friend_status'] = 'friends';
            }
        }

        return response()->json([
            'data' => $user,
        ], 200);
    }

    // Profile

    public function wall($id)
    {
        $user = User::select(['id'])->where('id', $id)->first();
        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $page = request()->query('page', 1);
        $path = request()->url();
        $query = request()->query();

        $data = Cache::remember('user:'.$id.':wall:page:'.$page, 30, function () use ($id) {
            $paginated = UserProfileWall::select(['id', 'user_id', 'author_id', 'content', 'created_at'])
                ->where('user_id', $id)
                ->with('author:id,username')
                ->orderBy('created_at', 'desc')
                ->paginate(6);

            return $paginated->toArray();
        });

        $wall = new LengthAwarePaginator(
            $data['data'],
            $data['total'],
            $data['per_page'],
            $data['current_page'],
            ['path' => $path, 'query' => $query]
        );

        return response()->json($wall);
    }

    public function postToWall($id, PostToWallRequest $request)
    {
        $data = $request->validated();
        $user = User::select(['id'])->where('id', $id)->first();
        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $currentUser = app('token_user');
        if ($user->privacy->who_can_post_on_wall == 2 && $user->id != $currentUser->id) {
            return response()->json([
                'status' => 'error',
                'message' => "This user's wall is locked.",
            ], 404);
        }

        $wall = new UserProfileWall;
        $wall->user_id = $user->id;
        $wall->author_id = $currentUser->id;
        $wall->content = $data['content'];
        $wall->save();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '').'user:'.$id.':wall:page:%')
            ->delete();

        return response()->json([], 201);
    }

    public function deleteWallPost($id)
    {
        $currentUser = app('token_user');

        if (! in_array($currentUser->role, ['admin', 'moderator'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 403);
        }

        $post = UserProfileWall::where('id', $id)->first();
        if (! $post) {
            return response()->json([
                'status' => 'error',
                'message' => 'Post not found',
            ], 404);
        }

        $post->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '').'user:'.$post->user_id.':wall:page:%')
            ->delete();

        return response()->json([], 200);
    }

    // Inventory

    public function meInventory()
    {
        $user = app('token_user');
        $category = request()->query('category', 'all');
        $page = request()->query('page', 1);
        $limit = request()->query('limit', 20);
        $showDuplicates = request()->query('show_duplicates', 0);

        $cacheKey = 'inventory:user:'.$user->id.':category:'.$category.':page:'.$page.':limit:'.$limit.':dup:'.$showDuplicates;

        $path = request()->url();
        $query = request()->query();

        $data = Cache::remember($cacheKey, 30, function () use ($user, $category, $limit, $showDuplicates) {
            $itemsQuery = MarketplaceItemInventory::select(['id', 'item_id', 'user_id', 'serial', 'price'])
                ->where('user_id', $user->id)
                ->whereHas('item', function ($query) {
                    $query->where('moderation_status', 'approved');
                })
                ->whereHas('item.category', function ($query) {
                    $query->where('needs_rendering', true);
                })
                ->with('item:id,title,texture_path,price,rap,rarity,category_id,is_limited,stock_count,stock_left,is_offsale')
                ->with('item.category:id,title');

            if ($category !== 'all') {
                $itemsQuery->whereHas('item', function ($query) use ($category) {
                    $query->whereHas('category', function ($q) use ($category) {
                        $q->where('title', $category);
                    });
                });
            }

            if ($showDuplicates == 0) {
                $itemsQuery->groupBy('item_id');
            }

            $paginated = $itemsQuery->paginate($limit);

            return $paginated->toArray();
        });

        $items = new LengthAwarePaginator(
            $data['data'],
            $data['total'],
            $data['per_page'],
            $data['current_page'],
            ['path' => $path, 'query' => $query]
        );

        return response()->json($items);
    }

    public function openCase($id)
    {
        $user = app('token_user');

        $caseInventory = MarketplaceItemInventory::select(['id', 'item_id', 'user_id'])
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->with('item')
            ->first();

        if (! $caseInventory) {
            return response()->json([
                'message' => 'Case not found in your inventory',
            ], 404);
        }

        if ($caseInventory->item->category->title !== 'Boxes') {
            return response()->json([
                'message' => 'This item is not a case',
            ], 422);
        }

        $caseContents = MarketplaceCaseContent::where('case_id', $caseInventory->item_id)
            ->with('item:id,title,price')
            ->get();

        if ($caseContents->isEmpty()) {
            return response()->json([
                'message' => 'This case has no contents',
            ], 422);
        }

        $totalWeight = $caseContents->sum('chance');

        $randomWeight = floatval(mt_rand() / mt_getrandmax()) * $totalWeight;

        $wonContent = null;
        $currentWeight = 0;

        foreach ($caseContents as $content) {
            $currentWeight += $content->chance;
            if ($randomWeight <= $currentWeight) {
                $wonContent = $content;
                break;
            }
        }

        if (! $wonContent) {
            $wonContent = $caseContents->last();
        }

        $serial = $wonContent->item->sold_count + 1;
        $wonInventory = MarketplaceItemInventory::create([
            'item_id' => $wonContent->item_id,
            'user_id' => $user->id,
            'serial' => $serial,
            'price' => $wonContent->item->price,
        ]);

        $caseInventory->delete();

        QuestController::incrementProgress($user->id, 'Open Cases', 1);
        QuestController::incrementProgress($user->id, 'Open Many Cases', 1);
        QuestController::incrementProgress($user->id, 'Case Opener', 1);

        // Log activity for opening a case
        ActivityLog::log(
            $user->id,
            'case_open',
            "opened a case and won {$wonContent->item->title}",
            $wonContent->item,
            ['case_name' => $caseInventory->item->title, 'won_item' => $wonContent->item->title]
        );

        return response()->json([
            'data' => $wonInventory->load('item', 'item.category'),
        ], 200);
    }

    public function inventory($id)
    {
        $limit = request()->query('limit', 0);
        $pagination = request()->query('pagination', true);
        $category = request()->query('category', 0);
        $page = request()->query('page', 1);

        $cacheKey = 'inventory:user:'.$id.':category:'.$category.':limit:'.$limit.':pagination:'.$pagination.':page:'.$page;

        $user = Cache::remember('user:'.$id, 60, function () use ($id) {
            return User::select(['id'])->where('id', $id)->first()->toArray();
        });

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $path = request()->url();
        $query = request()->query();

        $items = Cache::remember($cacheKey, 30, function () use ($page, $user, $category, $limit, $pagination) {
            $itemsQuery = MarketplaceItemInventory::select(['id', 'item_id', 'user_id', 'serial', 'price'])
                ->where('user_id', $user['id'])
                ->whereHas('item', function ($query) {
                    $query->where('moderation_status', 'approved');
                })
                ->with('item:id,title,price,rap,rarity,category_id,texture_path,is_limited,stock_count,stock_left,is_offsale')
                ->with('item.category:id,title');

            if ($category != 0) {
                $itemsQuery->whereHas('item', function ($query) use ($category) {
                    $query->where('category_id', $category);
                });
            }

            if ($pagination) {
                return $itemsQuery->orderBy('created_at', 'desc')->paginate($limit, ['*'], 'page', $page)->toArray();
            }

            if ($limit != 0) {
                return $itemsQuery->orderBy('created_at', 'desc')->limit($limit)->get()->toArray();
            }

            return $itemsQuery->orderBy('created_at', 'desc')->get()->toArray();
        });

        return response()->json($items);
    }

    public function creations($id)
    {
        $limit = request()->query('limit', 12);
        $page = request()->query('page', 1);

        $cacheKey = 'creations:user:'.$id.':limit:'.$limit.':page:'.$page;

        $user = Cache::remember('user:'.$id, 60, function () use ($id) {
            return User::select(['id'])->where('id', $id)->first()->toArray();
        });

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $path = request()->url();
        $query = request()->query();

        $data = Cache::remember($cacheKey, 30, function () use ($user, $limit, $page) {
            $clothingCategories = ['Shirts', 'Pants'];

            $paginated = MarketplaceItem::select(['id', 'price', 'user_id', 'category_id', 'title', 'description', 'texture_path', 'price', 'rap', 'rarity', 'created_at'])
                ->where('user_id', $user['id'])
                ->where('moderation_status', 'approved')
                ->whereHas('category', function ($q) use ($clothingCategories) {
                    $q->whereIn('title', $clothingCategories);
                })
                ->with('category:id,title')
                ->orderBy('created_at', 'desc')
                ->paginate($limit, ['*'], 'page', $page);

            return $paginated->toArray();
        });

        $creations = new LengthAwarePaginator(
            $data['data'],
            $data['total'],
            $data['per_page'],
            $data['current_page'],
            ['path' => $path, 'query' => $query]
        );

        return response()->json($creations);
    }

    // Friending
    public function requests()
    {
        $user = app('token_user');

        $requests = UserFriendRequest::select(['id', 'from_id', 'to_id', 'created_at'])
            ->where('to_id', $user->id)
            ->with('from:id,username')
            ->paginate(5);

        return response()->json($requests);
    }

    public function sendFriendRequest($toId)
    {
        $to = User::select(['id'])->where('id', $toId)->first();
        if (! $to) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $from = app('token_user');

        if ($from->id == $to->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You cannot send a friend request to yourself.',
            ], 403);
        }

        $request = UserFriendRequest::select(['id'])
            ->where('from_id', $from->id)->where('to_id', $to->id)
            ->first();
        if ($request) {
            return response()->json([
                'status' => 'error',
                'message' => 'You have already sent a friend request to this user.',
            ], 403);
        }

        $request = new UserFriendRequest;
        $request->from_id = $from->id;
        $request->to_id = $to->id;
        $request->save();

        QuestController::incrementProgress($from->id, 'Send Friend Requests', 1);
        QuestController::incrementProgress($from->id, 'Social Butterfly', 1);

        return response()->json([], 201);
    }

    public function changeRequestState($id, $state)
    {
        $request = UserFriendRequest::select(['id', 'from_id', 'to_id'])
            ->where('id', $id)
            ->first();
        $user = app('token_user');

        if (! $request) {
            return response()->json([
                'status' => 'error',
                'message' => 'Request not found',
            ], 404);
        }

        if ($request->to_id != $user->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'You are not the recipient of this request',
            ], 403);
        }

        if ($state == 0) {
            $friend = new UserFriend;
            $friend->first_id = $user->id;
            $friend->second_id = $request->from_id;
            $friend->save();

            $request->delete();

            return response()->json([], 200);
        } elseif ($state == 1) {
            $request->delete();

            return response()->json([], 200);
        }
    }

    public function unfriend($id)
    {
        $user = app('token_user');
        $friend = UserFriend::select(['id'])
            ->where('first_id', $user->id)->where('second_id', $id)
            ->orWhere('first_id', $id)->where('second_id', $user->id)
            ->first();
        if ($friend) {
            $friend->delete();
        }

        return response()->json([], 200);
    }

    public function leaderboard()
    {
        $page = request()->query('page', 1);
        $perPage = 9;

        $data = Cache::remember("leaderboard:page:{$page}", 60, function () use ($page, $perPage) {
            $paginator = User::select(['id', 'username', 'bubble', 'last_seen_at', 'final_rap', 'item_count'])
                ->orderBy('final_rap', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $startingRank = ($page - 1) * $perPage + 1;

            $items = collect($paginator->items())->map(function ($user, $index) use ($startingRank) {
                return [
                    'rank' => $startingRank + $index,
                    'id' => $user->id,
                    'username' => $user->username,
                    'final_rap' => $user->final_rap,
                    'item_count' => $user->item_count,
                    'bubble' => $user->bubble,
                    'last_seen_at' => $user->last_seen_at,
                ];
            })->values();

            return [
                'items' => $items->all(),
                'total' => $paginator->total(),
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

    // Newest Users

    public function newestUsers()
    {
        $users = Cache::remember('newest_users', 60, function () {
            return User::select(['id', 'username', 'created_at'])
                ->orderBy('created_at', 'desc')
                ->limit(16)
                ->get()->toArray();
        });

        return response()->json([
            'data' => $users,
        ], 200);
    }

    // Browse Users

    public function browseUsers()
    {
        $search = request()->query('search', '');
        $sortBy = request()->query('sort_by', 'newest');
        $page = request()->query('page', 1);
        $perPage = 20;

        $cacheKey = 'browse_users:'.md5($search.':'.$sortBy.':'.$page);

        $usersData = Cache::remember($cacheKey, 120, function () use ($search, $sortBy, $page, $perPage) {
            $query = User::select(['id', 'username', 'bubble', 'rap', 'final_rap', 'avatar_frame_id', 'created_at', 'last_seen_at'])->where('role', '!=', 'banned');

            if ($search) {
                $query->where('username', 'like', "%{$search}%");
            }

            switch ($sortBy) {
                case 'oldest':
                    $query->orderBy('created_at', 'asc');
                    break;
                case 'highest_rap':
                    $query->orderBy('final_rap', 'desc');
                    break;
                case 'newest':
                default:
                    $query->orderBy('created_at', 'desc');
                    break;
            }

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            return [
                'items' => collect($paginator->items())->toArray(),
                'total' => $paginator->total(),
            ];
        });

        $paginated = new LengthAwarePaginator(
            $usersData['items'],
            $usersData['total'],
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return response()->json($paginated);

    }

    // Petitions

    public function indexPetitions()
    {
        $page = request()->query('page', 1);
        $perPage = 10;

        $user = app('token_user');
        $userId = $user ? (is_object($user) ? $user->id : $user['id']) : 'guest';
        $cacheKey = 'petitions:page:'.$page.':perPage:'.$perPage;

        $data = Cache::remember($cacheKey, 60, function () use ($perPage, $page) {
            return Petition::select(['id', 'user_id', 'title', 'description', 'type', 'upvotes', 'downvotes', 'approved', 'created_at'])
                ->with('user:id,username')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page)
                ->toArray();
        });

        $userVotes = [];
        if ($userId && ! empty($data['data'])) {
            $petitionIds = collect($data['data'])->pluck('id')->all();

            $userVotes = DB::table('petition_votes')
                ->where('user_id', $userId)
                ->whereIn('petition_id', $petitionIds)
                ->pluck('vote', 'petition_id')
                ->all();
        }

        foreach ($data['data'] as &$petition) {
            $petition['user_vote'] = $userVotes[$petition['id']] ?? null;
        }

        return response()->json($data);
    }

    public function createPetition()
    {
        $user = app('token_user');
        $data = request()->all();

        $petition = Petition::create([
            'user_id' => $user->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'type' => $data['type'],
        ]);

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '').'petitions:%')
            ->delete();

        return response()->json(['data' => $petition->load('user')], 201);
    }

    public function votePetition($id)
    {
        $user = app('token_user');
        $petition = Petition::select(['id', 'user_id', 'upvotes', 'downvotes'])
            ->where('id', $id)
            ->first();

        if (! $petition) {
            return response()->json(['message' => 'Petition not found'], 404);
        }

        $vote = request()->input('vote');
        $voteColumn = $vote.'s';
        $existingVote = PetitionVote::select(['id', 'vote'])
            ->where('petition_id', $id)->where('user_id', $user->id)
            ->first();

        if ($existingVote) {
            if ($existingVote->vote === $vote) {
                $existingVote->delete();
                $petition->decrement($voteColumn);
            } else {
                $oldVoteColumn = $existingVote->vote.'s';
                $existingVote->update(['vote' => $vote]);
                $petition->decrement($oldVoteColumn);
                $petition->increment($voteColumn);
            }
        } else {
            PetitionVote::create([
                'petition_id' => $id,
                'user_id' => $user->id,
                'vote' => $vote,
            ]);
            $petition->increment($voteColumn);
        }

        return response()->json(['data' => $petition->load('user')]);
    }

    public function approvePetition($id)
    {
        $user = app('token_user');

        if ($user->role !== 'admin' && $user->role !== 'moderator') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $petition = Petition::select(['id', 'user_id'])
            ->where('id', $id)
            ->first();
        if (! $petition) {
            return response()->json(['message' => 'Petition not found'], 404);
        }

        $petition->update(['approved' => true]);

        return response()->json(['data' => $petition->load('user')]);
    }

    // Roadmap

    public function indexRoadmap()
    {
        $items = RoadmapItem::select(['id', 'title', 'description', 'status', 'phase', 'sort_order', 'created_at'])
            ->orderBy('sort_order')
            ->get();

        return response()->json($items);
    }

    public function createRoadmapItem()
    {
        $user = app('token_user');

        if ($user->role !== 'admin' && $user->role !== 'moderator') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = request()->all();

        $item = RoadmapItem::create([
            'title' => $data['title'],
            'description' => $data['description'],
            'status' => $data['status'] ?? 'planned',
            'phase' => $data['phase'],
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return response()->json(['data' => $item], 201);
    }

    public function updateRoadmapItem($id)
    {
        $user = app('token_user');

        if ($user->role !== 'admin' && $user->role !== 'moderator') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $item = RoadmapItem::find($id);
        if (! $item) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        $data = request()->all();
        $item->update($data);

        return response()->json(['data' => $item]);
    }

    public function deleteRoadmapItem($id)
    {
        $user = app('token_user');

        if ($user->role !== 'admin' && $user->role !== 'moderator') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $item = RoadmapItem::find($id);
        if (! $item) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        $item->delete();

        return response()->json(['message' => 'Deleted']);
    }

    // Avatar

    public function getWearing()
    {
        $user = app('token_user');

        $wearing = UserWearing::select(['id', 'user_id', 'item_id'])
            ->where('user_id', $user->id)
            ->with('item:id,title,texture_path,price,rap,rarity,category_id')
            ->with('item.category:id,title')
            ->get();

        return response()->json(['data' => $wearing]);
    }

    public function getAvatarColors()
    {
        $user = app('token_user');

        $colors = UserAvatarColor::where('user_id', $user->id)->first();

        if (! $colors) {
            $colors = UserAvatarColor::create([
                'user_id' => $user->id,
                'left_arm_color' => '#D9C5B2',
                'right_arm_color' => '#D9C5B2',
                'torso_color' => '#D9C5B2',
                'left_leg_color' => '#D9C5B2',
                'right_leg_color' => '#D9C5B2',
                'head_color' => '#D9C5B2',
            ]);
        }

        return response()->json(['data' => $colors]);
    }

    public function wearItem($inventoryId)
    {
        $user = app('token_user');

        $inventory = MarketplaceItemInventory::select(['id', 'item_id', 'user_id'])
            ->where('id', $inventoryId)
            ->where('user_id', $user->id)
            ->with('item.category')
            ->first();

        if (! $inventory) {
            return response()->json([
                'message' => 'Item not found in your inventory',
            ], 404);
        }

        $existing = UserWearing::select(['id'])
            ->where('user_id', $user->id)
            ->where('item_id', $inventory->item_id)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Item is already equipped',
            ], 422);
        }

        // Enforce one-item-per-category limit for "Face" and "Avatar Poses"
        $restrictedCategories = ['Face', 'Avatar Poses'];
        if (in_array($inventory->item->category->title, $restrictedCategories)) {
            $alreadyWearingCategory = UserWearing::select(['user_wearing.id'])
                ->join('marketplace_items', 'user_wearing.item_id', '=', 'marketplace_items.id')
                ->join('marketplace_categories', 'marketplace_items.category_id', '=', 'marketplace_categories.id')
                ->where('user_wearing.user_id', $user->id)
                ->where('marketplace_categories.title', $inventory->item->category->title)
                ->first();

            if ($alreadyWearingCategory) {
                return response()->json([
                    'message' => 'You can only wear one '.$inventory->item->category->title.' item at a time',
                ], 422);
            }
        }

        UserWearing::create([
            'user_id' => $user->id,
            'item_id' => $inventory->item_id,
        ]);

        return response()->json(['data' => $inventory->load('item', 'item.category')], 201);
    }

    public function removeWornItem($inventoryId)
    {
        $user = app('token_user');

        $inventory = MarketplaceItemInventory::select(['id', 'item_id', 'user_id'])
            ->where('id', $inventoryId)
            ->where('user_id', $user->id)
            ->first();

        if (! $inventory) {
            return response()->json([
                'message' => 'Item not found in your inventory',
            ], 404);
        }

        UserWearing::where('user_id', $user->id)
            ->where('item_id', $inventory->item_id)
            ->delete();

        return response()->json([], 200);
    }

    public function saveAvatarColors()
    {
        $user = app('token_user');

        $data = request()->all();

        $colors = UserAvatarColor::updateOrCreate(
            ['user_id' => $user->id],
            [
                'left_arm_color' => $data['left_arm_color'] ?? '#D9C5B2',
                'right_arm_color' => $data['right_arm_color'] ?? '#D9C5B2',
                'torso_color' => $data['torso_color'] ?? '#556B8E',
                'left_leg_color' => $data['left_leg_color'] ?? '#4A4A4A',
                'right_leg_color' => $data['right_leg_color'] ?? '#4A4A4A',
                'head_color' => $data['head_color'] ?? '#D9C5B2',
            ]
        );

        return response()->json(['data' => $colors], 200);
    }

    public function renderAvatar()
    {
        $user = app('token_user');
        $wearing = $user->wearing;
        $colors = $user->avatarColors;

        if (! $user->is_email_verified) {
            return response()->json([
                'message' => 'Contact support',
            ], 422);
        }

        $renderer = new PythonRenderHelper;
        $renderer->loadBlend(config('app.renderer_directory').'/scene.blend');

        $colorMap = [
            'head' => $colors->head_color,
            'torso' => $colors->torso_color,
            'left_arm' => $colors->left_arm_color,
            'right_arm' => $colors->right_arm_color,
            'left_leg' => $colors->left_leg_color,
            'right_leg' => $colors->right_leg_color,
        ];

        $hasFace = false;
        foreach ($wearing as $wearingItem) {
            $item = $wearingItem->item;
            $category = $item->category;

            if ($category->title == 'Gears') {
                $posXyz = ['x' => 0.913747, 'y' => -2.35409, 'z' => 0.836074];
                $rotXyz = ['x' => -0.000009, 'y' => -90, 'z' => 0];
                $renderer->setPosition('left_arm', $posXyz);
                $renderer->rotate('left_arm', $rotXyz);
            }

            if ($category->has_model) {
                $modelPath = config('app.renderer_directory').'/'.$item->model_path;
                $renderer->loadObj($item->id, $modelPath, $category->has_texture);

                $texturePath = config('app.renderer_directory').'/'.$item->texture_path;
                $renderer->addTexture($item->id, $item->id.'_tex', $texturePath);

                $renderer->addRawCode("obj_{$item->id}.parent = bpy.data.objects['{$category->parts_affected}']");
                $renderer->addRawCode("obj_{$item->id}.matrix_parent_inverse = bpy.data.objects['{$category->parts_affected}'].matrix_world.inverted()");

            }

            if ($category->has_texture && $item->texture_path) {
                $parts = $category->parts_affected_array;
                $texturePath = config('app.renderer_directory').'/'.$item->texture_path;
                foreach ($parts as $part) {
                    if ($part === 'head') {
                        $hasFace = true;
                    }
                    $renderer->addTexture($part, $item->id.'_tex', $texturePath);
                }
            }

            if ($category->title == 'Avatar Poses') {
                $definition = AvatarPoseDefinition::where('item_id', $item->id)->first();
                $parts = explode(';', $definition['definition']);

                foreach ($parts as $part) {
                    $data = explode(':', $part);
                    // [0] - Part name
                    // [1] - Part X, [2] - Part Y, [3] - Part Z
                    // [4] - Part Rot X, [5] - Part Rot Y, [6] - Part Rot Z
                    $renderer->setPosition($data[0], ['x' => $data[1], 'y' => $data[2], 'z' => $data[3]]);
                    $renderer->rotate($data[0], ['x' => $data[4], 'y' => $data[5], 'z' => $data[6]]);
                }
            }
        }

        if (! $hasFace) {
            $renderer->addTexture('head', 'default_face', config('app.renderer_directory').'/textures/def_face.png');
        }

        $parts = ['head', 'left_arm', 'right_arm', 'torso', 'right_leg', 'left_leg'];
        foreach ($parts as $part) {
            $renderer->selectAndColor($part, $colorMap[$part]);
        }

        $hash = md5($user->id.time());
        $outputPath = config('app.storage_directory').'/avatars';

        $renderer->focus('all');
        $renderer->save($user->id, $outputPath);

        $outputPath = config('app.storage_directory').'/headshots';
        $renderer->focus(['head', 'torso']);
        $renderer->save($user->id, $outputPath);

        if (file_put_contents(config('app.renderer_directory')."/python/$hash.py", $renderer->getScript())) {
            $output = '';
            $code = 0;
            exec(config('app.blender_path').' -b -P '.config('app.renderer_directory')."/python/$hash.py 2>&1", $output, $code);

            return response()->json([
                'data' => [
                    'render_url' => "/{$user->id}.png",
                    'hash' => $hash,
                    'output' => $output,
                    'code' => $code,
                ],
            ], 200);
        }

    }

    public function createInvoice()
    {
        $user = app('token_user');
        $data = request()->all();
        $request = Http::withoutVerifying()->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-Api-Key' => env('PAYMENT_API_KEY', null)])
            ->post('https://gate.lava.top/api/v3/invoice', ['offerId' => env('PAYMENT_OFFER_ID', null), 'amount' => $data['amount'], 'currency' => 'USD', 'email' => $user->email]);

        $data = $request->json();

        $contract = new UserPaymentContract;
        $contract->user_id = $user->id;
        $contract->contract_uuid = $data['id'];
        $contract->amount = $data['amountTotal']['amount'];
        $contract->save();

        return response()->json([
            'data' => $data,
        ], 200);
    }

    // Transactions

    public function transactions()
    {
        $user = app('token_user');

        $cacheKey = 'user:transactions:'.$user->id;

        $data = Cache::remember($cacheKey, 60, function () use ($user) {
            $stats = Transaction::selectRaw("COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) as total, COALESCE(SUM(CASE WHEN type = 'clothing' AND status = 'approved' THEN amount ELSE 0 END), 0) as clothing, COALESCE(SUM(CASE WHEN type = 'reselling' AND status = 'approved' THEN amount ELSE 0 END), 0) as reselling, COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending")
                ->where('user_id', $user->id)
                ->first();

            return [
                'total' => (int) $stats->total,
                'clothing' => (int) $stats->clothing,
                'games' => 0,
                'reselling' => (int) $stats->reselling,
                'pending' => (int) $stats->pending,
            ];
        });

        return response()->json([
            'data' => $data,
        ], 200);
    }

    public function pendingTransactions($id)
    {
        $user = app('token_user');

        if ($user->role !== 'admin' && $user->role !== 'moderator') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $targetUser = User::select(['id', 'username'])->where('id', $id)->first();
        if (! $targetUser) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $transactions = Transaction::select(['id', 'user_id', 'from_user_id', 'type', 'item_id', 'reference_id', 'amount', 'status', 'created_at'])
            ->where('user_id', $id)
            ->where('status', 'pending')
            ->with('fromUser:id,username,created_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $transactions,
            'user' => $targetUser,
        ], 200);
    }

    public function verifyTransaction($id)
    {
        $user = app('token_user');

        if ($user->role !== 'admin' && $user->role !== 'moderator') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $transaction = Transaction::find($id);
        if (! $transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        $action = request()->input('action');

        if ($action === 'approve') {
            $transaction->status = 'approved';

            $transaction->user->coins = $transaction->user->coins + $transaction->amount;
            $transaction->user->save();

            if ($transaction->type == 'clothing' || $transaction->type == 'reselling') {
                QuestController::incrementProgress($transaction->user_id, 'Sell Items on Marketplace', 1);
                QuestController::incrementProgress($transaction->user_id, 'Marketplace Tycoon', 1);
            }
        } elseif ($action === 'deny') {
            $transaction->status = 'denied';
        } else {
            return response()->json(['message' => 'Invalid action'], 422);
        }

        $transaction->admin_note = request()->input('admin_note');
        $transaction->save();

        Cache::forget('user:transactions:'.$transaction->user_id);

        AdminLog::create([
            'admin_id' => $user->id,
            'target_id' => $transaction->user_id,
            'log' => "Transaction #{$transaction->id} {$action}d (type: {$transaction->type}, amount: {$transaction->amount})",
        ]);

        return response()->json([
            'data' => $transaction,
        ], 200);
    }

    public function activityFeed()
    {
        $activities = ActivityLog::select(['id', 'user_id', 'type', 'description', 'metadata', 'created_at'])
            ->with('user:id,username,avatar_frame_id')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $activities,
        ], 200);
    }

    /**
     * Return the 4 newest approved items belonging to admin-only categories.
     *
     * @return JsonResponse
     */
    public function newestItems()
    {
        $items = Cache::remember('homepage:newest_items', 60, function () {
            return MarketplaceItem::select([
                'id', 'user_id', 'category_id', 'title', 'price', 'rap', 'rarity',
                'is_limited', 'stock_left', 'created_at',
            ])
                ->where('is_deleted', false)
                ->where('moderation_status', 'approved')
                ->whereHas('category', function ($q) {
                    $q->where('is_admin_only', true);
                })
                ->with('user:id,username')
                ->with('category:id,title')
                ->orderBy('created_at', 'desc')
                ->limit(4)
                ->get()
                ->toArray();
        });

        return response()->json([
            'data' => $items,
        ], 200);
    }

    /**
     * Return the 4 newest forum threads (posts).
     *
     * @return JsonResponse
     */
    public function newestPosts()
    {
        $threads = Cache::remember('homepage:newest_posts', 60, function () {
            return ForumThread::select(['id', 'category_id', 'title', 'user_id', 'is_pinned', 'created_at'])
                ->where('is_deleted', false)
                ->with('user:id,username')
                ->with('category:id,name')
                ->orderBy('created_at', 'desc')
                ->limit(4)
                ->get()
                ->toArray();
        });

        return response()->json([
            'data' => $threads,
        ], 200);
    }

    /**
     * Return the 3 newest published blog posts for the homepage.
     *
     * @return JsonResponse
     */
    public function newestBlogPosts()
    {
        $posts = Cache::remember('homepage:newest_blog_posts', 60, function () {
            return BlogPost::select(['id', 'title', 'banner_path', 'user_id', 'is_featured', 'created_at'])
                ->where('is_published', true)
                ->where('is_deleted', false)
                ->with('user:id,username,avatar_frame_id')
                ->orderBy('is_featured', 'desc')
                ->orderBy('created_at', 'desc')
                ->limit(3)
                ->get()
                ->toArray();
        });

        return response()->json([
            'data' => $posts,
        ], 200);
    }

    public function userCollections($id)
    {
        $user = User::find($id);
        if (! $user) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        $collections = Collection::withCount('items')->get();

        $userInventoryIds = MarketplaceItemInventory::where('user_id', $id)
            ->pluck('item_id')
            ->unique()
            ->toArray();

        $alreadyCompleted = $user->collections()->pluck('collections.id')->toArray();

        $result = $collections->map(function ($collection) use ($id, $userInventoryIds, $alreadyCompleted) {
            $collectionItemIds = $collection->items()->pluck('marketplace_item_id')->toArray();
            $totalItems = count($collectionItemIds);
            $collectedItems = count(array_intersect($collectionItemIds, $userInventoryIds));

            $percentage = $totalItems > 0 ? round(($collectedItems / $totalItems) * 100) : 0;

            $status = 'not_started';
            if ($collectedItems >= $totalItems && $totalItems > 0) {
                $status = 'completed';
            } elseif ($collectedItems > 0) {
                $status = 'in_progress';
            }

            $rewards = null;
            if ($status === 'completed' && ! in_array($collection->id, $alreadyCompleted)) {
                $rewards = $this->completeCollectionIfNeeded($collection, $userInventoryIds, $id);
            }

            return [
                'id' => $collection->id,
                'name' => $collection->name,
                'description' => $collection->description,
                'image' => $collection->image,
                'total_items' => $totalItems,
                'collected_items' => $collectedItems,
                'percentage' => $percentage,
                'status' => $status,
                'rewards' => $rewards,
            ];
        });

        $statusOrder = ['completed' => 0, 'in_progress' => 1, 'not_started' => 2];
        $result = $result->sortBy(function ($item) use ($statusOrder) {
            return $statusOrder[$item['status']] ?? 3;
        })->values();

        return response()->json([
            'data' => $result,
        ], 200);
    }

    /**
     * Grant collection rewards (forum tag, coins, XP) if the collection is fully collected
     * and hasn't been completed by this user before.
     *
     * @param  Collection  $collection
     * @param  array  $userInventoryIds
     * @return array|null
     */
    private function completeCollectionIfNeeded($collection, $userInventoryIds, $id)
    {
        $collectionItemIds = $collection->items()->pluck('marketplace_item_id')->toArray();
        $totalItems = count($collectionItemIds);

        if ($totalItems === 0) {
            return null;
        }

        $collectedItems = count(array_intersect($collectionItemIds, $userInventoryIds));

        if ($collectedItems < $totalItems) {
            return null;
        }

        $user = app('token_user');

        // Check if the current user is viewing their own collections
        if (! $user || $user->id != $id) {
            return null;
        }

        // Mark as completed in the pivot table to prevent double rewards
        $alreadyCompleted = $user->collections()->where('collections.id', $collection->id)->exists();
        if ($alreadyCompleted) {
            return null;
        }

        $user->collections()->attach($collection->id);

        $rewards = [
            'coins' => 0,
            'xp' => 0,
            'forum_tag' => null,
        ];

        // Grant coin reward
        if ($collection->coin_reward > 0) {
            $rewards['coins'] = $collection->coin_reward;
            $user->coins = $user->coins + $collection->coin_reward;
        }

        // Grant XP reward
        if ($collection->xp_reward > 0) {
            $rewards['xp'] = $collection->xp_reward;
            $user->giveExp($collection->xp_reward);
        }

        // Grant forum tag reward
        if ($collection->forum_tag_id) {
            $existingInventory = ForumTagInventory::where('user_id', $user->id)
                ->where('forum_tag_id', $collection->forum_tag_id)
                ->exists();

            if (! $existingInventory) {
                ForumTagInventory::create([
                    'user_id' => $user->id,
                    'forum_tag_id' => $collection->forum_tag_id,
                ]);

                $tag = ForumTag::find($collection->forum_tag_id);
                $rewards['forum_tag'] = $tag ? $tag->toArray() : null;
            }
        }

        $user->save();

        // Log activity for collection completion
        ActivityLog::log(
            $user->id,
            'collection_complete',
            "completed the collection: {$collection->name}",
            $collection,
            ['collection_id' => $collection->id, 'rewards' => $rewards]
        );

        return $rewards;
    }
}
