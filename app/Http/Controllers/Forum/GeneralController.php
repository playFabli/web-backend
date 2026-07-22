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

class GeneralController extends Controller
{
    public function categories()
    {
        $categories = ForumCategory::all();

        return response()->json([
            'data' => $categories,
        ], 200);
    }

    public function threads($categoryId = 0)
    {
        $query = request()->query('query', '');
        if ($categoryId == 0) {
            // all
            $threads = ForumThread::where('is_deleted', false)
                ->where(function ($q) use ($query) {
                    $q->where('title', 'like', "%$query%")
                        ->orWhere('content', 'like', "%$query%");
                })
                ->with('user')
                ->with('category')
                ->orderBy('is_pinned', 'desc')
                ->orderBy('created_at', 'desc')
                ->paginate(9);

            return response()->json($threads, 200);
        } else {
            $threads = ForumThread::where('category_id', $categoryId)
                ->where('is_deleted', false)
                ->where(function ($q) use ($query) {
                    $q->where('title', 'like', "%$query%")
                        ->orWhere('content', 'like', "%$query%");
                })
                ->with('user')
                ->with('category')
                ->orderBy('is_pinned', 'desc')
                ->orderBy('created_at', 'desc')
                ->paginate(9);

            return response()->json($threads, 200);
        }
    }

    public function thread($id)
    {
        $thread = ForumThread::where('id', $id)
            ->where('is_deleted', false)
            ->with('user')
            ->with('category')
            ->first();

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
        $replies = ForumReply::where('thread_id', $threadId)
            ->where('is_deleted', false)
            ->with('user')
            ->orderBy('created_at', 'asc')
            ->paginate(9);

        return response()->json($replies, 200);
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

        $reply = ForumReply::create([
            'thread_id' => $threadId,
            'content' => $data['content'],
            'user_id' => $user->id,
        ]);

        $user->giveExp(1);

        // Track quest progress for forum replies
        QuestController::incrementProgress($user->id, 'Forum Engagement', 1);
        QuestController::incrementProgress($user->id, 'Interact with other players on the forums', 1);

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

        return response()->json([], 200);
    }
}
