<?php

namespace App\Http\Controllers\Forum;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forum\CreateReplyRequest;
use App\Http\Requests\Forum\CreateThreadRequest;
use Illuminate\Http\Request;

class GeneralController extends Controller
{
    public function categories() {
        $categories = \App\Models\ForumCategory::all();
        return response()->json([
            "data" => $categories
        ], 200);
    }

    public function threads($categoryId = 0, ) {
        $query = request()->query("query", "");
        if($categoryId == 0) {
            // all
            $threads = \App\Models\ForumThread::where("is_deleted", false)
                ->where(function($q) use ($query) {
                    $q->where("title", "like", "%$query%")
                      ->orWhere("content", "like", "%$query%");
                })
                ->with("user")
                ->with("category")
                ->orderBy("is_pinned", "desc")
                ->orderBy("created_at", "desc")
                ->paginate(9);

            return response()->json($threads, 200);
        } else {
            $threads = \App\Models\ForumThread::where("category_id", $categoryId)
                ->where("is_deleted", false)
                ->where(function($q) use ($query) {
                    $q->where("title", "like", "%$query%")
                      ->orWhere("content", "like", "%$query%");
                })
                ->with("user")
                ->with("category")
                ->orderBy("is_pinned", "desc")
                ->orderBy("created_at", "desc")
                ->paginate(9);

            return response()->json($threads, 200);
        }
    }

    public function thread($id) {
        $thread = \App\Models\ForumThread::where("id", $id)
            ->where("is_deleted", false)
            ->with("user")
            ->with("category")
            ->first();

        if(!$thread) {
            return response()->json([
                "message" => "Thread not found"
            ], 404);
        }

        // add view
        $user = app("token_user");
        $existingView = \App\Models\ForumThreadView::where("thread_id", $id)
            ->where("user_id", $user->id)
            ->first();
        if(!$existingView) {
            \App\Models\ForumThreadView::create([
                "thread_id" => $id,
                "user_id" => $user->id
            ]);
        }

        return response()->json([
            "data" => $thread
        ], 200);
    }

    public function replies($threadId) {
        $replies = \App\Models\ForumReply::where("thread_id", $threadId)
            ->where("is_deleted", false)
            ->with("user")
            ->orderBy("created_at", "asc")
            ->paginate(9);

        return response()->json($replies, 200);
    }

    public function createThread($categoryId, CreateThreadRequest $request) {
        $data = $request->validated();
        $user = app("token_user");

        $category = \App\Models\ForumCategory::where("id", $categoryId)->where("is_locked", false)->first();
        if(!$category) {
            return response()->json([
                "message" => "Category not found"
            ], 404);
        }

        $thread = \App\Models\ForumThread::create([
            "category_id" => $categoryId,
            "title" => $data["title"],
            "content" => $data["content"],
            "user_id" => $user->id
        ]);

        $user->giveExp(2);

        // Track quest progress for forum posts
        \App\Http\Controllers\User\QuestController::incrementProgress($user->id, "Post on Forums", 1);
        \App\Http\Controllers\User\QuestController::incrementProgress($user->id, "Forum Engagement", 1);

        return response()->json([
            "data" => $thread
        ], 201);

    }

    public function createReply($threadId, CreateReplyRequest $request) {
        $data = $request->validated();
        $user = app("token_user");

        $thread = \App\Models\ForumThread::where("id", $threadId)->where("is_deleted", false)->where("is_locked",false)->first();
        if(!$thread) {
            return response()->json([
                "message" => "Thread not found"
            ], 404);
        }

        $reply = \App\Models\ForumReply::create([
            "thread_id" => $threadId,
            "content" => $data["content"],
            "user_id" => $user->id
        ]);

        $user->giveExp(1);

        // Track quest progress for forum replies
        \App\Http\Controllers\User\QuestController::incrementProgress($user->id, "Forum Engagement", 1);
        \App\Http\Controllers\User\QuestController::incrementProgress($user->id, "Interact with other players on the forums", 1);

        return response()->json([
            "data" => $reply
        ], 201);

    }

    // Admin/Moderator actions for threads
    public function scrubThread($id) {
        $thread = \App\Models\ForumThread::where("id", $id)->first();
        if(!$thread) {
            return response()->json([
                "message" => "Thread not found"
            ], 404);
        }
        $thread->is_scrubbed = true;
        $thread->save();
        return response()->json([], 200);
    }

    public function deleteThread($id) {
        $thread = \App\Models\ForumThread::where("id", $id)->first();
        if(!$thread) {
            return response()->json([
                "message" => "Thread not found"
            ], 404);
        }
        $thread->is_deleted = !$thread->is_deleted;
        $thread->save();
        return response()->json([], 200);
    }

    public function pinThread($id) {
        $thread = \App\Models\ForumThread::where("id", $id)->first();
        if(!$thread) {
            return response()->json([
                "message" => "Thread not found"
            ], 404);
        }
        $thread->is_pinned = !$thread->is_pinned;
        $thread->save();
        return response()->json([], 200);
    }

    public function lockThread($id) {
        $thread = \App\Models\ForumThread::where("id", $id)->first();
        if(!$thread) {
            return response()->json([
                "message" => "Thread not found"
            ], 404);
        }
        $thread->is_locked = !$thread->is_locked;
        $thread->save();
        return response()->json([], 200);
    }

    // Admin/Moderator actions for replies
    public function scrubReply($id) {
        $reply = \App\Models\ForumReply::where("id", $id)->first();
        if(!$reply) {
            return response()->json([
                "message" => "Reply not found"
            ], 404);
        }
        $reply->is_scrubbed = true;
        $reply->save();
        return response()->json([], 200);
    }

    public function deleteReply($id) {
        $reply = \App\Models\ForumReply::where("id", $id)->first();
        if(!$reply) {
            return response()->json([
                "message" => "Reply not found"
            ], 404);
        }
        $reply->is_deleted = !$reply->is_deleted;
        $reply->save();
        return response()->json([], 200);
    }
}
