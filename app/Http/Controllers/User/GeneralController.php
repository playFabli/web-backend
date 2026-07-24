<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Helpers\PythonRenderHelper;
use App\Http\Requests\User\PostToWallRequest;
use App\Models\MarketplaceCaseContent;
use App\Models\MarketplaceItemInventory;
use App\Models\Petition;
use App\Models\PetitionVote;
use App\Models\RoadmapItem;
use App\Models\User;
use App\Models\UserAvatarColor;
use App\Models\UserBan;
use App\Models\UserFriend;
use App\Models\UserFriendRequest;
use App\Models\UserPaymentContract;
use App\Models\UserProfileWall;
use App\Models\UserWearing;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
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

        if ($latestBan) {
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
                'id', 'username', 'description', 'bubble', 'level', 'exp', 'coins', 'role',
                'rap', 'is_email_verified', 'last_seen_at', 'created_at',
            ])
                ->where('id', $id)
                ->with('privacy')
                ->first();
        });

        if (! $from || ! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $user['friend_status'] = 'none';

        $fromId = is_object($from) ? $from->id : $from['id'];
        $request = UserFriendRequest::select(['id', 'from_id', 'to_id'])
            ->where('from_id', $fromId)->where('to_id', $user->id)
            ->orWhere('from_id', $user->id)->where('to_id', $fromId)
            ->first();
        if ($request) {
            if ($request->from_id == $fromId) {
                $user['friend_status'] = 'sent';
            } else {
                $user['friend_status'] = 'received';
            }
        } else {
            $friends = UserFriend::select(['id', 'first_id', 'second_id'])
                ->where('first_id', $fromId)->where('second_id', $user->id)
                ->orWhere('first_id', $user->id)->where('second_id', $fromId)
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

        return response()->json([], 201);
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
                ->with('item:id,title,texture_path,price,rap,rarity,category_id')
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
            ->with('item:id,title,sold_count,price')
            ->get();

        if ($caseContents->isEmpty()) {
            return response()->json([
                'message' => 'This case has no contents',
            ], 422);
        }

        $wonContent = $caseContents->random();

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

        return response()->json([
            'data' => $wonInventory->load('item', 'item.category'),
        ], 200);
    }

    public function inventory($id)
    {
        $limit = request()->query('limit', 0);
        $pagination = request()->query('pagination', true);
        $category = request()->query('category', 0);

        $cacheKey = 'inventory:user:'.$id.':category:'.$category.':limit:'.$limit.':pagination:'.$pagination;

        $user = Cache::remember('user:'.$id, 60, function () use ($id) {
            return User::select(['id'])->where('id', $id)->first();
        });

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $path = request()->url();
        $query = request()->query();

        $items = Cache::remember($cacheKey, 30, function () use ($user, $category, $limit, $pagination) {
            $itemsQuery = MarketplaceItemInventory::select(['id', 'item_id', 'user_id', 'serial', 'price'])
                ->where('user_id', $user->id)
                ->whereHas('item', function ($query) {
                    $query->where('moderation_status', 'approved');
                })
                ->with('item:id,title,texture_path,price,rap,rarity,category_id')
                ->with('item.category:id,title');

            if ($category != 0) {
                $itemsQuery->whereHas('item', function ($query) use ($category) {
                    $query->where('category_id', $category);
                });
            }

            if ($pagination) {
                return $itemsQuery->paginate($limit)->toArray();
            }

            if ($limit != 0) {
                return $itemsQuery->limit($limit)->get();
            }

            return $itemsQuery->get();
        });

        return response()->json($items);
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

        $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
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
                ->limit(10)
                ->get();
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

        if ($search) {
            $cacheKey = 'browse_users:search:'.md5($search).':sort:'.$sortBy.':page:'.$page;
        } else {
            $cacheKey = 'browse_users:all:sort:'.$sortBy.':page:'.$page;
        }

        $users = Cache::remember($cacheKey, 120, function () use ($search, $sortBy, $perPage) {
            $query = User::select(['id', 'username', 'bubble', 'rap', 'created_at', 'last_seen_at']);

            if ($search) {
                $query->where('username', 'like', "%{$search}%");
            }

            $totalCount = $query->count();

            $usersCollection = $query->get();

            switch ($sortBy) {
                case 'oldest':
                    $usersCollection = $usersCollection->sortBy('created_at');
                    break;

                case 'highest_rap':
                    $usersCollection = $usersCollection->sortByDesc('final_rap');
                    break;

                case 'newest':
                default:
                    $usersCollection = $usersCollection->sortByDesc('created_at');
                    break;
            }

            $currentPageItems = $usersCollection->slice(0, $perPage)->values();

            return [
                'items' => $currentPageItems,
                'total' => $totalCount,
                'perPage' => $perPage,
                'currentPage' => 1,
            ];
        });

        $paginated = new LengthAwarePaginator(
            $users['items'],
            $users['total'],
            $users['perPage'],
            $users['currentPage'],
            ['path' => request()->url(), 'query' => request()->query()]
        );

        // Ensure page is correct after retrieving from cache
        $pageItems = $paginated->getCollection()->slice(($page - 1) * $perPage, $perPage)->values();
        $paginated->setCollection($pageItems);

        return response()->json($paginated);
    }

    // Petitions

    public function indexPetitions()
    {
        $page = request()->query('page', 1);
        $perPage = 10;

        $user = app('token_user');
        $userId = $user ? (is_object($user) ? $user->id : $user['id']) : 'guest';
        $cacheKey = 'petitions:page:'.$page.':perPage:'.$perPage.':user:'.$userId;

        $petitions = Cache::remember($cacheKey, 60, function () use ($perPage, $page, $user) {
            $paginated = Petition::select(['id', 'user_id', 'title', 'description', 'type', 'upvotes', 'downvotes', 'approved', 'created_at'])
                ->with('user:id,username')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            $paginated->getCollection()->transform(function ($petition) use ($user) {
                $petition->user_vote = $petition->votes->where('user_id', $user->id)->first()?->vote ?? null;

                return $petition;
            });

            return $paginated;
        });

        $petitions->getCollection()->transform(function ($petition) use ($user) {
            $petition->user_vote = $petition->votes->where('user_id', $user->id)->first()?->vote ?? null;

            return $petition;
        });

        return response()->json($petitions);
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
            ->with('item')
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

            if ($category->has_model) {
                $modelPath = config('app.renderer_directory').'/'.$item->model_path;
                $renderer->loadObj($item->id, $modelPath, $category->has_texture);

                $texturePath = config('app.renderer_directory').'/'.$item->texture_path;
                $renderer->addTexture($item->id, $item->id.'_tex', $texturePath);
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
}
