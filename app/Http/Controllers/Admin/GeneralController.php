<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Helpers\PythonRenderHelper;
use App\Models\AdminLog;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceItemInventory;
use App\Models\User;
use App\Models\UserBan;
use App\Models\UserToken;
use Illuminate\Http\Request;

class GeneralController extends Controller
{
    private function log($adminId, $targetId, $log)
    {
        AdminLog::create([
            'admin_id' => $adminId,
            'target_id' => $targetId,
            'log' => $log,
        ]);
    }

    private function getAdmin()
    {
        $token = str_replace('Bearer ', '', request()->header('Authorization'));
        $userToken = UserToken::where('token', $token)->first();

        return $userToken ? $userToken->user : null;
    }

    public function dashboard()
    {
        $totalUsers = User::count();
        $totalItems = MarketplaceItem::count();
        $totalInventory = MarketplaceItemInventory::count();
        $pendingItems = MarketplaceItem::where('moderation_status', 'pending')->count();
        $totalBans = UserBan::count();
        $totalLogs = AdminLog::count();
        $recentLogs = AdminLog::with('admin')->with('target')->orderBy('created_at', 'desc')->limit(10)->get();

        return response()->json([
            'data' => [
                'total_users' => $totalUsers,
                'total_items' => $totalItems,
                'total_inventory' => $totalInventory,
                'pending_items' => $pendingItems,
                'total_bans' => $totalBans,
                'total_logs' => $totalLogs,
                'recent_logs' => $recentLogs,
            ],
        ], 200);
    }

    // ---------- Users ----------

    public function users(Request $request)
    {
        $query = $request->query('query', '');
        $role = $request->query('role', '');
        $page = $request->query('page', 1);
        $perPage = 15;

        $usersQuery = User::query();

        if (! empty($query)) {
            $usersQuery->where(function ($q) use ($query) {
                $q->where('username', 'like', "%$query%")
                    ->orWhere('email', 'like', "%$query%");
            });
        }

        if (! empty($role) && in_array($role, ['user', 'moderator', 'admin'])) {
            $usersQuery->where('role', $role);
        }

        $users = $usersQuery->withCount('bans')->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($users, 200);
    }

    public function user($id)
    {
        $user = User::where('id', $id)->with('bans.bannedBy')->first();
        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        return response()->json([
            'data' => $user,
        ], 200);
    }

    public function updateUser($id, Request $request)
    {
        $admin = $this->getAdmin();
        $user = User::where('id', $id)->first();
        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $data = $request->validate([
            'username' => ['sometimes', 'string', 'min:3', 'max:255', 'unique:users,username,'.$id],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,'.$id],
            'password' => ['sometimes', 'string', 'min:6'],
            'role' => ['sometimes', 'string', 'in:user,moderator,admin'],
            'description' => ['sometimes', 'string', 'max:255'],
            'bubble' => ['sometimes', 'string', 'max:255'],
            'coins' => ['sometimes', 'integer', 'min:0'],
            'rap' => ['sometimes', 'integer', 'min:0'],
        ]);

        $changes = [];
        foreach ($data as $key => $value) {
            if ($key === 'password') {
                continue;
            }
            if ($value != $user->$key) {
                $changes[] = "$key: '".$user->$key."' -> '".$value."'";
            }
        }

        if (isset($data['password'])) {
            $data['password'] = bcrypt($data['password']);
            $changes[] = 'password updated';
        }

        $user->update($data);

        if (! empty($changes) && $admin) {
            $this->log($admin->id, $user->id, 'Updated user: '.implode(', ', $changes));
        }

        return response()->json([
            'data' => $user,
        ], 200);
    }

    public function deleteUser($id)
    {
        $admin = $this->getAdmin();
        $user = User::where('id', $id)->first();
        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $username = $user->username;
        $userId = $user->id;
        $user->delete();

        if ($admin) {
            $this->log($admin->id, $userId, "Deleted user: $username (ID: $userId)");
        }

        return response()->json([], 200);
    }

    // ---------- Bans ----------

    public function banUser($id, Request $request)
    {
        $admin = $this->getAdmin();
        $user = User::where('id', $id)->first();
        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $data = $request->validate([
            'banned_for' => ['nullable', 'string', 'max:1000'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $existingBans = UserBan::where('user_id', $user->id)->count();

        $ban = UserBan::create([
            'user_id' => $user->id,
            'banned_by_admin_id' => $admin ? $admin->id : 0,
            'banned_for' => $data['banned_for'] ?? null,
            'banned_at' => now(),
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        if ($admin) {
            $this->log($admin->id, $user->id, 'Banned user (ban #'.($existingBans + 1).'): '.($data['banned_for'] ?? 'No reason given'));
        }

        return response()->json([
            'data' => $ban,
        ], 200);
    }

    public function unbanUser($id)
    {
        $admin = $this->getAdmin();
        $user = User::where('id', $id)->first();
        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $ban = UserBan::where('user_id', $user->id)->latest()->first();
        if (! $ban) {
            return response()->json([
                'status' => 'error',
                'message' => 'User is not banned',
            ], 404);
        }

        $ban->delete();

        if ($admin) {
            $this->log($admin->id, $user->id, 'Unbanned user');
        }

        return response()->json([], 200);
    }

    public function userBans($id)
    {
        $user = User::where('id', $id)->first();
        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $bans = UserBan::where('user_id', $user->id)->with('bannedBy')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $bans,
        ], 200);
    }

    // ---------- Items / Assets ----------

    public function assets(Request $request)
    {
        $query = $request->query('query', '');
        $status = $request->query('status', '');
        $page = $request->query('page', 1);
        $perPage = 15;

        $itemsQuery = MarketplaceItem::with('user')->with('category');

        if (! empty($query)) {
            $itemsQuery->where(function ($q) use ($query) {
                $q->where('title', 'like', "%$query%");
            });
        }

        if (! empty($status) && in_array($status, ['pending', 'approved', 'unapproved'])) {
            $itemsQuery->where('moderation_status', $status);
        }

        $items = $itemsQuery->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($items, 200);
    }

    public function asset($id)
    {
        $item = MarketplaceItem::where('id', $id)->with('user')->with('category')->first();
        if (! $item) {
            return response()->json([
                'status' => 'error',
                'message' => 'Item not found',
            ], 404);
        }

        return response()->json([
            'data' => $item,
        ], 200);
    }

    public function updateAsset($id, Request $request)
    {
        $admin = $this->getAdmin();
        $item = MarketplaceItem::where('id', $id)->first();
        if (! $item) {
            return response()->json([
                'status' => 'error',
                'message' => 'Item not found',
            ], 404);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'string'],
            'price' => ['sometimes', 'integer', 'min:0'],
            'rap' => ['sometimes', 'integer', 'min:0'],
            'rarity' => ['sometimes', 'string', 'in:none,uncommon,rare,epic,legendary'],
            'category_id' => ['sometimes', 'exists:marketplace_categories,id'],
            'is_limited' => ['sometimes', 'boolean'],
            'stock_count' => ['sometimes', 'integer', 'min:0'],
            'stock_left' => ['sometimes', 'integer', 'min:0'],
            'is_offsale' => ['sometimes', 'boolean'],
            'moderation_status' => ['sometimes', 'string', 'in:pending,approved,unapproved'],
        ]);

        $changes = [];
        foreach ($data as $key => $value) {
            if ($value != $item->$key) {
                $changes[] = "$key changed";
            }
        }

        $item->update($data);

        if ($admin) {
            $this->log($admin->id, $item->user_id, "Updated asset #$id (\"".$item->title.'"): '.(! empty($changes) ? implode(', ', $changes) : 'no changes'));
        }

        return response()->json([
            'data' => $item,
        ], 200);
    }

    public function grantAsset($id, Request $request)
    {
        $admin = $this->getAdmin();
        $item = MarketplaceItem::where('id', $id)->first();
        if (! $item) {
            return response()->json([
                'status' => 'error',
                'message' => 'Item not found',
            ], 404);
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::where('id', $data['user_id'])->first();
        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $inventory = MarketplaceItemInventory::create([
            'item_id' => $item->id,
            'user_id' => $user->id,
            'serial' => $item->sold_count + 1,
            'price' => 0,
        ]);

        if ($admin) {
            $this->log($admin->id, $user->id, "Granted asset #$id to user #{$user->id}");
        }

        return response()->json([
            'data' => $inventory,
        ], 201);
    }

    public function deleteAsset($id)
    {
        $admin = $this->getAdmin();
        $item = MarketplaceItem::where('id', $id)->first();
        if (! $item) {
            return response()->json([
                'status' => 'error',
                'message' => 'Item not found',
            ], 404);
        }

        $title = $item->title;
        $item->is_deleted = ! $item->is_deleted;
        $item->save();

        if ($admin) {
            $this->log($admin->id, $item->user_id, "Deleted asset #$id (\"".$title.'")');
        }

        return response()->json([], 200);
    }

    public function pendingItems()
    {
        $items = MarketplaceItem::where('moderation_status', 'pending')
            ->with('user')
            ->with('category')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($items, 200);
    }

    public function approveItem($id)
    {
        $admin = $this->getAdmin();
        $item = MarketplaceItem::where('id', $id)->first();
        if (! $item) {
            return response()->json([
                'status' => 'error',
                'message' => 'Item not found',
            ], 404);
        }

        $item->moderation_status = 'approved';
        $item->save();

        if ($admin) {
            $this->log($admin->id, $item->user_id, "Approved asset #$id (\"".$item->title.'")');
        }

        return response()->json([
            'data' => $item,
        ], 200);
    }

    public function createAsset(Request $request)
    {
        $admin = $this->getAdmin();

        $data = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['required', 'exists:marketplace_categories,id'],
            'price' => ['required', 'integer', 'min:0'],
            'rap' => ['required', 'integer', 'min:0'],
            'rarity' => ['required', 'string', 'in:none,uncommon,rare,epic,legendary'],
            'limited' => ['sometimes', 'in:true,false,1,0'],
            'stock_count' => ['sometimes', 'integer', 'min:0'],
            'stock_left' => ['sometimes', 'integer', 'min:0'],
            'offsale' => ['sometimes', 'in:true,false,1,0'],
            'moderation_status' => ['required', 'string', 'in:pending,approved,unapproved'],
        ]);

        $category = MarketplaceCategory::find($data['category_id']);

        $texturePath = null;
        if ($request->hasFile('texture')) {
            $file = $request->file('texture');
            $filename = time();
            $storageDir = rtrim(config('app.renderer_directory'), '/\\').DIRECTORY_SEPARATOR.'textures';
            $moved = $file->move($storageDir, $filename.'.png');
            if ($moved) {
                $texturePath = 'textures/'.$filename.'.png';
            }
        }

        $modelPath = null;
        if ($request->hasFile('model')) {
            $file = $request->file('model');
            $filename = time();
            $storageDir = rtrim(config('app.renderer_directory'), '/\\').DIRECTORY_SEPARATOR.'models';
            $moved = $file->move($storageDir, $filename.'.obj');
            if ($moved) {
                $modelPath = 'models/'.$filename;
            }
        }

        $offsale = filter_var($data['offsale'], FILTER_VALIDATE_BOOLEAN);
        $limited = filter_var($data['limited'], FILTER_VALIDATE_BOOLEAN);

        if ($data['rarity'] == 'epic') {
            // dumbass
            $data['rarity'] = 'ultra_rare';
        }

        $item = MarketplaceItem::create([
            'user_id' => 1, // Admin-created items have user_id 0
            'category_id' => $data['category_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? '',
            'texture_path' => $texturePath,
            'model_path' => $modelPath,
            'price' => $data['price'],
            'rap' => $data['rap'],
            'rarity' => $data['rarity'],
            'is_limited' => $limited ?? false,
            'stock_count' => $data['stock_count'] ?? 0,
            'stock_left' => $data['stock_left'] ?? 0,
            'is_offsale' => $offsale ?? false,
            'moderation_status' => $data['moderation_status'],
        ]);

        // Render the item thumbnail
        $this->renderItem($item);

        if ($admin) {
            $this->log($admin->id, 0, 'Created asset #'.$item->id.' ("'.$item->title.'")');
        }

        return response()->json([
            'data' => $item,
        ], 201);
    }

    public function renderItem($item)
    {
        $category = $item->category;

        $renderer = new PythonRenderHelper;
        $renderer->loadBlend(config('app.renderer_directory').'/scene.blend');

        // All colors should be pure white for item rendering
        $whiteColor = '#FFFFFF';

        // Load the 3D model if the category has one and model was uploaded
        if ($category->has_model && $item->model_path) {
            $modelPath = config('app.renderer_directory').'/'.$item->model_path;
            $renderer->loadObj($item->id, $modelPath, $category->has_texture);

            $texturePath = config('app.renderer_directory').'/'.$item->texture_path;
            $renderer->addTexture($item->id, $item->id.'_tex', $texturePath);
        }

        // Apply texture if the category has one and the item has a texture path
        if ($category->has_texture && $item->texture_path) {
            $parts = $category->parts_affected_array;
            $texturePath = config('app.renderer_directory').'/'.$item->texture_path;
            foreach ($parts as $part) {
                $renderer->addTexture($part, $item->id.'_tex', $texturePath);
            }
        }

        // Apply white colors to all parts
        $parts = ['head', 'left_arm', 'right_arm', 'torso', 'right_leg', 'left_leg'];
        foreach ($parts as $part) {
            $renderer->selectAndColor($part, $whiteColor);
        }

        // Generate a unique hash for the render
        $hash = md5($item->id.time());
        $outputPath = config('app.storage_directory').'/items';

        $renderer->focus(['all']);
        $renderer->save($item->id, $outputPath);

        $script = $renderer->getScript();
        if (file_put_contents(config('app.renderer_directory')."/python/$hash.py", $script)) {
            $output = [];
            $code = 0;
            exec(config('app.blender_path').' -b -P '.config('app.renderer_directory')."/python/$hash.py 2>&1", $output, $code);

            return response()->json([
                'data' => [
                    'render_url' => "/{$item->id}.png",
                    'hash' => $hash,
                    'output' => $output,
                    'code' => $code,
                ]]);
        }

        return response()->json(['error' => 'Failed to render item'], 500);
    }

    public function rejectItem($id)
    {
        $admin = $this->getAdmin();
        $item = MarketplaceItem::where('id', $id)->first();
        if (! $item) {
            return response()->json([
                'status' => 'error',
                'message' => 'Item not found',
            ], 404);
        }

        $item->moderation_status = 'unapproved';
        $item->save();

        if ($admin) {
            $this->log($admin->id, $item->user_id, "Rejected asset #$id (\"".$item->title.'")');
        }

        return response()->json([
            'data' => $item,
        ], 200);
    }

    // ---------- Logs ----------

    public function logs(Request $request)
    {
        $page = $request->query('page', 1);
        $perPage = 20;

        $logs = AdminLog::with('admin')->with('target')->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($logs, 200);
    }
}
