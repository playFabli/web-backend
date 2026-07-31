<?php

namespace App\Http\Controllers\Forum;

use App\Http\Controllers\Controller;
use App\Models\ForumTag;
use App\Models\ForumTagInventory;
use Illuminate\Http\Request;

class ForumTagController extends Controller
{
    public function index()
    {
        $tags = ForumTag::orderBy('name')->get();

        return response()->json([
            'data' => $tags,
        ], 200);
    }

    public function myTags()
    {
        $user = app('token_user');

        $tags = ForumTagInventory::where('user_id', $user->id)
            ->with('forumTag')
            ->get()
            ->pluck('forumTag')
            ->filter()
            ->values();

        return response()->json([
            'data' => $tags,
        ], 200);
    }

    public function select(Request $request)
    {
        $user = app('token_user');

        $data = $request->validate([
            'forum_tag_id' => ['nullable', 'integer', 'exists:forum_tags,id'],
        ]);

        $forumTagId = $data['forum_tag_id'] ?? null;

        if ($forumTagId) {
            $owned = ForumTagInventory::where('user_id', $user->id)
                ->where('forum_tag_id', $forumTagId)
                ->exists();

            if (! $owned) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not own this forum tag.',
                ], 403);
            }
        }

        $user->selected_forum_tag_id = $forumTagId;
        $user->save();

        $selectedTag = $forumTagId ? ForumTag::find($forumTagId) : null;

        return response()->json([
            'data' => [
                'selected_forum_tag_id' => $user->selected_forum_tag_id,
                'selected_forum_tag' => $selectedTag,
            ],
        ], 200);
    }
}
