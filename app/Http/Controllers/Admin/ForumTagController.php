<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use App\Models\ForumTag;
use App\Models\ForumTagInventory;
use App\Models\User;
use App\Models\UserToken;
use Illuminate\Http\Request;

class ForumTagController extends Controller
{
    private function getAdmin()
    {
        $token = str_replace('Bearer ', '', request()->header('Authorization'));
        $userToken = UserToken::where('token', $token)->first();

        return $userToken ? $userToken->user : null;
    }

    private function log($adminId, $targetId, $log)
    {
        AdminLog::create([
            'admin_id' => $adminId,
            'target_id' => $targetId,
            'log' => $log,
        ]);
    }

    public function index()
    {
        $tags = ForumTag::withCount('inventories')->orderBy('name')->get();

        return response()->json([
            'data' => $tags,
        ], 200);
    }

    public function store(Request $request)
    {
        $admin = $this->getAdmin();

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'style' => ['nullable', 'string'],
        ]);

        $tag = ForumTag::create([
            'name' => $data['name'],
            'style' => $data['style'] ?? null,
        ]);

        if ($admin) {
            $this->log($admin->id, $tag->id, "Created forum tag #{$tag->id} (\"{$tag->name}\")");
        }

        return response()->json([
            'data' => $tag,
        ], 201);
    }

    public function update($id, Request $request)
    {
        $admin = $this->getAdmin();
        $tag = ForumTag::find($id);

        if (! $tag) {
            return response()->json([
                'message' => 'Forum tag not found',
            ], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'style' => ['nullable', 'string'],
        ]);

        $tag->update($data);

        if ($admin) {
            $this->log($admin->id, $tag->id, "Updated forum tag #{$tag->id} (\"{$tag->name}\")");
        }

        return response()->json([
            'data' => $tag,
        ], 200);
    }

    public function destroy($id)
    {
        $admin = $this->getAdmin();
        $tag = ForumTag::find($id);

        if (! $tag) {
            return response()->json([
                'message' => 'Forum tag not found',
            ], 404);
        }

        $name = $tag->name;
        $tag->delete();

        if ($admin) {
            $this->log($admin->id, $id, "Deleted forum tag #{$id} (\"{$name}\")");
        }

        return response()->json([], 200);
    }

    public function grant(Request $request)
    {
        $admin = $this->getAdmin();

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'forum_tag_id' => ['required', 'integer', 'exists:forum_tags,id'],
        ]);

        $user = User::find($data['user_id']);
        if (! $user) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        $tag = ForumTag::find($data['forum_tag_id']);
        if (! $tag) {
            return response()->json([
                'message' => 'Forum tag not found',
            ], 404);
        }

        $existing = ForumTagInventory::where('user_id', $user->id)
            ->where('forum_tag_id', $tag->id)
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'error',
                'message' => 'This user already has this forum tag.',
            ], 422);
        }

        $inventory = ForumTagInventory::create([
            'user_id' => $user->id,
            'forum_tag_id' => $tag->id,
        ]);

        if ($admin) {
            $this->log($admin->id, $user->id, "Granted forum tag #{$tag->id} (\"{$tag->name}\") to user #{$user->id}");
        }

        return response()->json([
            'data' => $inventory->load('forumTag'),
        ], 201);
    }

    public function revoke(Request $request)
    {
        $admin = $this->getAdmin();

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'forum_tag_id' => ['required', 'integer', 'exists:forum_tags,id'],
        ]);

        $inventory = ForumTagInventory::where('user_id', $data['user_id'])
            ->where('forum_tag_id', $data['forum_tag_id'])
            ->first();

        if (! $inventory) {
            return response()->json([
                'message' => 'This user does not have this forum tag.',
            ], 404);
        }

        $user = User::find($data['user_id']);
        if ($user && $user->selected_forum_tag_id == $data['forum_tag_id']) {
            $user->selected_forum_tag_id = null;
            $user->save();
        }

        $inventory->delete();

        if ($admin) {
            $this->log($admin->id, $data['user_id'], "Revoked forum tag #{$data['forum_tag_id']} from user #{$data['user_id']}");
        }

        return response()->json([], 200);
    }
}
