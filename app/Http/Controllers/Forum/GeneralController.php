<?php

namespace App\Http\Controllers\Forum;

use App\Http\Controllers\Controller;
use App\Http\Controllers\User\QuestController;
use App\Http\Requests\Forum\CreateReplyRequest;
use App\Http\Requests\Forum\CreateThreadRequest;
use App\Models\ForumCategory;
use App\Models\ForumReply;
use App\Models\ForumThread;
use App\Models\ForumThreadView;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GeneralController extends Controller
{
    public function categories()
    {
        $categories = Cache::remember('forum:categories', 3600, function () {
            return ForumCategory::all()->toArray();
        });

        return response()->json([
            'data' => $categories,
        ], 200);
    }

    public function threads($categoryId = 0)
    {
        $query = request()->query('query', '');
        $page = request()->query('page', 1);
        $cacheKey = 'forum:threads:category:'.$categoryId.':query:'.md5($query).':page:'.$page;

        $threads = Cache::remember($cacheKey, 60, function () use ($categoryId, $query) {
            if ($categoryId == 0) {
                return ForumThread::where('is_deleted', false)
                    ->where(function ($q) use ($query) {
                        $q->where('title', 'like', "%$query%")
                            ->orWhere('content', 'like', "%$query%");
                    })
                    ->with('user')
                    ->with('category')
                    ->orderBy('is_pinned', 'desc')
                    ->orderBy('created_at', 'desc')
                    ->paginate(9)->toArray();
            }

            return ForumThread::where('category_id', $categoryId)
                ->where('is_deleted', false)
                ->where(function ($q) use ($query) {
                    $q->where('title', 'like', "%$query%")
                        ->orWhere('content', 'like', "%$query%");
                })
                ->with('user')
                ->with('category')
                ->orderBy('is_pinned', 'desc')
                ->orderBy('created_at', 'desc')
                ->paginate(9)->toArray();
        });

        return response()->json($threads, 200);
    }

    public function thread($id)
    {
        $thread = Cache::remember("forum:thread:{$id}", 60, function () use ($id) {
            return ForumThread::where('id', $id)
                ->where('is_deleted', false)
                ->with('user')
                ->with('category')
                ->first()->toArray();
        });

        if (! $thread) {
            return response()->json([
                'message' => 'Thread not found',
            ], 404);
        }

        // add view
        $user = app('token_user');
        $existingView = ForumThreadView::where('thread_id', $id)
            ->where('user_id', $user->id)
            ->first();
        if (! $existingView) {
            ForumThreadView::create([
                'thread_id' => $id,
                'user_id' => $user->id,
            ]);
        }

        return response()->json([
            'data' => $thread,
        ], 200);
    }

    public function replies($threadId)
    {
        $page = request()->query('page', 1);
        $path = request()->url();
        $query = request()->query();

        $data = Cache::remember("forum:thread:{$threadId}:replies:page:{$page}", 30, function () use ($threadId) {
            $paginated = ForumReply::where('thread_id', $threadId)
                ->where('is_deleted', false)
                ->with('user:id,username') 
                ->orderBy('created_at', 'asc')
                ->paginate(9)->toArray();

            return $paginated;
        });

        // $replies = new LengthAwarePaginator(
        //     $data['data'],         
        //     $data['total'],        
        //     $data['per_page'],     
        //     $data['current_page'], 
        //     ['path' => $path, 'query' => $query]
        // );

        return response()->json($data, 200);

    }

    public function createThread($categoryId, CreateThreadRequest $request)
    {
        $data = $request->validated();
        $user = app('token_user');

        $category = ForumCategory::where('id', $categoryId)->where('is_locked', false)->first();
        if (! $category) {
            return response()->json([
                'message' => 'Category not found',
            ], 404);
        }

        if(!$user->is_email_verified) {
            return response()->json([
                'message' => 'Contact support',
            ], 422);            
        }


        $thread = ForumThread::create([
            'category_id' => $categoryId,
            'title' => $data['title'],
            'content' => $data['content'],
            'user_id' => $user->id,
        ]);

        $user->giveExp(2);

        // Track quest progress for forum posts
        QuestController::incrementProgress($user->id, 'Post on Forums', 1);
        QuestController::incrementProgress($user->id, 'Forum Engagement', 1);

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:threads:category:{$thread->category_id}:%")
            ->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:threads:category:0:%")
            ->delete();

        return response()->json([
            'data' => $thread,
        ], 201);

    }

    public function createReply($threadId, CreateReplyRequest $request)
    {
        $data = $request->validated();
        $user = app('token_user');

        $thread = ForumThread::where('id', $threadId)->where('is_deleted', false)->where('is_locked', false)->first();
        if (! $thread) {
            return response()->json([
                'message' => 'Thread not found',
            ], 404);
        }

        if(!$user->is_email_verified) {
            return response()->json([
                'message' => 'Contact support',
            ], 422);            
        }

        $reply = ForumReply::create([
            'thread_id' => $threadId,
            'content' => $data['content'],
            'user_id' => $user->id,
        ]);

        $user->giveExp(1);

        // Track quest progress for forum replies
        QuestController::incrementProgress($user->id, 'Forum Engagement', 1);
        QuestController::incrementProgress($user->id, 'Interact with other players on the forums', 1);

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:thread:{$threadId}:replies:page:%")
            ->delete(); 

        return response()->json([
            'data' => $reply,
        ], 201);

    }

    // Admin/Moderator actions for threads
    public function scrubThread($id)
    {
        $thread = ForumThread::where('id', $id)->first();
        if (! $thread) {
            return response()->json([
                'message' => 'Thread not found',
            ], 404);
        }
        $thread->is_scrubbed = true;
        $thread->save();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:threads:category:{$thread->category_id}:%")
            ->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:threads:category:0:%")
            ->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:thread:{$thread->id}")
            ->delete();

        return response()->json([], 200);
    }

    public function deleteThread($id)
    {
        $thread = ForumThread::where('id', $id)->first();
        if (! $thread) {
            return response()->json([
                'message' => 'Thread not found',
            ], 404);
        }
        $thread->is_deleted = ! $thread->is_deleted;
        $thread->save();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:threads:category:{$thread->category_id}:%")
            ->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:threads:category:0:%")
            ->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:thread:{$thread->id}")
            ->delete();

        return response()->json([], 200);
    }

    public function pinThread($id)
    {
        $thread = ForumThread::where('id', $id)->first();
        if (! $thread) {
            return response()->json([
                'message' => 'Thread not found',
            ], 404);
        }
        $thread->is_pinned = ! $thread->is_pinned;
        $thread->save();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:threads:category:{$thread->category_id}:%")
            ->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:threads:category:0:%")
            ->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:thread:{$thread->id}")
            ->delete();

        return response()->json([], 200);
    }

    public function lockThread($id)
    {
        $thread = ForumThread::where('id', $id)->first();
        if (! $thread) {
            return response()->json([
                'message' => 'Thread not found',
            ], 404);
        }
        $thread->is_locked = ! $thread->is_locked;
        $thread->save();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:threads:category:{$thread->category_id}:%")
            ->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:threads:category:0:%")
            ->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:thread:{$thread->id}")
            ->delete();

        return response()->json([], 200);
    }

    // Admin/Moderator actions for replies
    public function scrubReply($id)
    {
        $reply = ForumReply::where('id', $id)->first();
        if (! $reply) {
            return response()->json([
                'message' => 'Reply not found',
            ], 404);
        }
        $reply->is_scrubbed = true;
        $reply->save();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:thread:{$reply->thread_id}:replies:page:%")
            ->delete(); 

        return response()->json([], 200);
    }

    public function deleteReply($id)
    {
        $reply = ForumReply::where('id', $id)->first();
        if (! $reply) {
            return response()->json([
                'message' => 'Reply not found',
            ], 404);
        }
        $reply->is_deleted = ! $reply->is_deleted;
        $reply->save();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "forum:thread:{$reply->thread_id}:replies:page:%")
            ->delete(); 

        return response()->json([], 200);
    }
}
