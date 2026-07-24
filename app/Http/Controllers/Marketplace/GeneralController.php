<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\User\QuestController;
use App\Http\Helpers\PythonRenderHelper;
use App\Http\Requests\Marketplace\CommentRequest;
use App\Http\Requests\Marketplace\CreateItemRequest;
use App\Http\Requests\Marketplace\CreateSellRequest;
use App\Http\Requests\Marketplace\PreviewRenderRequest;
use App\Http\Requests\Marketplace\UpdateItemRequest;
use App\Models\MarketplaceCaseContent;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceComment;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceItemInventory;
use App\Models\MarketplaceSellRequest;
use App\Models\MarketplaceSellRequestHistory;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GeneralController extends Controller
{
    public function categories($all = 1)
    {

        $categories = Cache::remember("marketplace:categories:all:{$all}", 3600, function () use ($all) {
            if (! $all) {
                return MarketplaceCategory::where('is_admin_only', 0)
                    ->get()
                    ->toArray();
            }

            return MarketplaceCategory::all()->toArray();
        });

        return response()->json([
            'data' => $categories,
            'all_param' => $all
        ], 200);
    }

    public function previewRender(PreviewRenderRequest $request)
    {
        $data = $request->validated();
        $category = MarketplaceCategory::find($data['category_id']);

        if (! $category) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        $file = $request->file('texture');
        $hash = md5(time().rand());
        $storageDir = rtrim(config('app.renderer_directory'), '/\\').DIRECTORY_SEPARATOR.'textures';
        $textureFilename = $hash.'.png';
        $moved = $file->move($storageDir, $textureFilename);
        if (! $moved) {
            return response()->json(['error' => 'Failed to save texture'], 500);
        }

        $texturePath = 'textures/'.$textureFilename;

        $renderer = new PythonRenderHelper;
        $renderer->loadBlend(config('app.renderer_directory').'/scene.blend');

        $whiteColor = '#FFFFFF';

        if ($category->has_model) {
        }

        if ($category->has_texture) {
            $parts = $category->parts_affected_array;
            $textureFullPath = config('app.renderer_directory').'/'.$texturePath;
            foreach ($parts as $part) {
                $renderer->addTexture($part, $hash.'_tex', $textureFullPath);
            }
        }

        $renderer->addTexture('head', 'default_face', config('app.renderer_directory').'/textures/def_face.png');

        $parts = ['head', 'left_arm', 'right_arm', 'torso', 'right_leg', 'left_leg'];
        foreach ($parts as $part) {
            $renderer->selectAndColor($part, $whiteColor);
        }

        $outputPath = config('app.storage_directory').'/items/previews';

        if (! is_dir($outputPath)) {
            mkdir($outputPath, 0755, true);
        }

        $renderer->focus(['all']);
        $renderer->save($hash, $outputPath);

        $script = $renderer->getScript();
        $pythonFile = config('app.renderer_directory')."/python/{$hash}.py";
        if (file_put_contents($pythonFile, $script)) {
            $output = [];
            $code = 0;
            exec(config('app.blender_path')." -b -P {$pythonFile} 2>&1", $output, $code);
        }

        return response()->json([
            'data' => [
                'preview_url' => "/storage/items/previews/{$hash}.png",
                'hash' => $hash,
            ],
        ], 200);
    }

    public function updateItem($id, UpdateItemRequest $request)
    {
        $item = MarketplaceItem::select(['id', 'user_id', 'texture_path', 'is_deleted', 'moderation_status'])
            ->where('id', $id)
            ->where('is_deleted', false)
            ->first();

        if (! $item) {
            return response()->json(['error' => 'Item not found'], 404);
        }

        $user = app('token_user');

        if ($item->user_id !== $user->id) {
            return response()->json(['error' => 'You do not have permission to edit this item.'], 403);
        }

        $data = $request->validated();

        if ($request->hasFile('texture')) {
            $file = $request->file('texture');
            $filename = time();
            $storageDir = rtrim(config('app.renderer_directory'), '/\\').DIRECTORY_SEPARATOR.'textures';
            $moved = $file->move($storageDir, $filename.'.png');
            if ($moved) {
                $data['texture_path'] = 'textures/'.$filename.'.png';
            }

            $item->moderation_status = 'pending';
            $item->save();
        }

        $item->update($data);

        if ($request->hasFile('texture')) {
            $this->renderItem($item);
        }

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "marketplace:item:{$request->item_id}")
            ->delete();
        
        return response()->json([
            'data' => $item,
        ], 200);
    }

    public function create(CreateItemRequest $request)
    {
        $data = $request->validated();
        $user = app('token_user');

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

        $item = MarketplaceItem::create([
            'user_id' => $user->id,
            'category_id' => $data['category_id'],
            'title' => $data['title'],
            'description' => $data['description'],
            'texture_path' => $texturePath,
            'price' => $data['price'],
            'moderation_status' => 'pending',
        ]);

        $inventory = new MarketplaceItemInventory;
        $inventory->item_id = $item->id;
        $inventory->user_id = $user->id;
        $inventory->serial = 1;
        $inventory->price = 0;
        $inventory->save();

        $this->renderItem($item);

        $prefix = config('cache.prefix', '');

        DB::table('cache')
            ->where('key', 'like', "{$prefix}marketplace:items:categories:%{$item->category_id}%")
            ->delete();

        return response()->json([
            'data' => $item,
        ], 201);
    }

    public function renderItem($item)
    {
        $category = $item->category;

        $renderer = new PythonRenderHelper;
        $renderer->loadBlend(config('app.renderer_directory').'/scene.blend');

        $whiteColor = '#FFFFFF';

        if ($category->has_model) {
            $modelPath = config('app.renderer_directory').'/models/'.$item->id;
            $renderer->loadObj($item->id, $modelPath, $category->has_texture);
        }

        if ($category->has_texture && $item->texture_path) {
            $parts = $category->parts_affected_array;
            $texturePath = config('app.renderer_directory').'/'.$item->texture_path;
            foreach ($parts as $part) {
                $renderer->addTexture($part, $item->id.'_tex', $texturePath);
            }
        }

        $renderer->addTexture('head', 'default_face', config('app.renderer_directory').'/textures/def_face.png');

        $parts = ['head', 'left_arm', 'right_arm', 'torso', 'right_leg', 'left_leg'];
        foreach ($parts as $part) {
            $renderer->selectAndColor($part, $whiteColor);
        }

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

    public function items($categories = '1,2,3,4,5')
    {
        $categories = explode(',', $categories);

        $priceMin = request()->query('price_min', 0);
        $priceMax = request()->query('price_max', 999999999);

        $rapMin = request()->query('rap_min', 0);
        $rapMax = request()->query('rap_max', 999999999);

        if ($priceMax == 'null') {
            $priceMax = 999999999;
        }

        if ($rapMax == 'null') {
            $rapMax = 999999999;
        }

        $query = request()->query('query', '');
        $page = request()->query('page', 1);

        $cacheKey = 'marketplace:items:categories:'.implode('_', $categories).":price:{$priceMin}:{$priceMax}:rap:{$rapMin}:{$rapMax}:query:".md5($query).":page:{$page}";

        $items = Cache::remember($cacheKey, 60, function () use ($categories, $priceMin, $priceMax, $rapMin, $rapMax, $query) {
            $paginator = MarketplaceItem::select([
                'id', 'user_id', 'category_id', 'title', 'price', 'rap', 'rarity',
                'is_limited', 'stock_count', 'stock_left', 'is_offsale', 'created_at',
            ])
                ->whereIn('category_id', $categories)
                ->where('is_deleted', false)
                ->where('moderation_status', 'approved')
                ->whereBetween('price', [$priceMin, $priceMax])
                ->whereBetween('rap', [$rapMin, $rapMax])
                ->where(function ($q) use ($query) {
                    $q->where('title', 'like', "%{$query}%")
                        ->orWhere('description', 'like', "%{$query}%");
                })
                ->with('user:id,username')
                ->with('category:id,title')
                ->orderBy('created_at', 'desc')
                ->paginate(12);

            return $paginator->toArray();
        });

        $user = app('token_user');
        if ($user) {
            QuestController::incrementProgress($user->id, 'Visit Marketplace', 1);
        }

        return response()->json($items, 200);
    }

    public function item($id)
    {
        $item = Cache::remember("marketplace:item:{$id}", 60, function () use ($id) {
            return MarketplaceItem::select([
                'id', 'user_id', 'category_id', 'title', 'description', 'price', 'rap', 'rarity',
                'texture_path', 'is_limited', 'is_offsale', 'stock_count', 'stock_left',
                'moderation_status', 'created_at', 'updated_at',
            ])
                ->where('id', $id)
                ->where('is_deleted', false)
                ->with('user:id,username')
                ->with('category:id,title')
                ->with(['comments' => function ($query) {
                    $query->select(['id', 'item_id', 'user_id', 'content', 'created_at'])
                        ->orderBy('created_at', 'desc');
                }, 'comments.user:id,username'])
                ->first()->toArray();
        });

        if (! $item) {
            return response()->json([
                'error' => 'Item not found',
            ], 404);
        }

        return response()->json([
            'data' => $item,
        ], 200);
    }

    public function comments($id)
    {
        $item = Cache::remember("marketplace:item:{$id}", 60, function () use ($id) {
            return MarketplaceItem::select(['id', 'is_deleted'])
                ->where('id', $id)
                ->where('is_deleted', false)
                ->first()->toArray();
        });

        if (! $item) {
            return response()->json([
                'error' => 'Item not found',
            ], 404);
        }

        $page = request()->query('page', 1);
        $comments = Cache::remember("marketplace:item:{$id}:comments:page:{$page}", 30, function () use ($id) {
            return MarketplaceComment::select(['id', 'item_id', 'user_id', 'content', 'created_at'])
                ->where('item_id', $id)
                ->with('user:id,username')
                ->orderBy('created_at', 'desc')
                ->get()->toArray();
        });

        return response()->json($comments, 200);
    }

    public function comment($id, CommentRequest $request)
    {
        $data = $request->validated();
        $item = MarketplaceItem::select(['id', 'is_deleted'])
            ->where('id', $id)
            ->where('is_deleted', false)
            ->first();

        if (! $item) {
            return response()->json([
                'error' => 'Item not found',
            ], 404);
        }

        $user = app('token_user');

        $comment = MarketplaceComment::create([
            'item_id' => $item->id,
            'user_id' => $user->id,
            'content' => $data['content'],
        ]);

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "marketplace:item:{$request->item_id}:comments:page:%")
            ->delete();

        return response()->json($comment, 201);
    }

    public function owns($id)
    {
        $item = MarketplaceItem::select(['id', 'is_deleted'])
            ->where('id', $id)
            ->where('is_deleted', false)
            ->first();

        if (! $item) {
            return response()->json([
                'message' => 'Item not found',
            ], 404);
        }

        $user = app('token_user');

        $alreadyBought = MarketplaceItemInventory::select(['id', 'item_id', 'user_id', 'serial', 'price'])
            ->where('item_id', $item->id)
            ->where('user_id', $user->id)
            ->get();

        return response()->json([
            'bool' => ($alreadyBought->isNotEmpty()),
            'data' => $alreadyBought,
        ]);
    }

    public function buy($id)
    {
        $item = MarketplaceItem::select(['id', 'user_id', 'title', 'price', 'is_limited', 'stock_left', 'is_deleted'])
            ->where('id', $id)
            ->where('is_deleted', false)
            ->first();

        if (! $item) {
            return response()->json([
                'message' => 'Item not found',
            ], 404);
        }

        $user = app('token_user');

        if ($item->user_id === $user->id) {
            return response()->json([
                'message' => 'Cannot buy your own item',
            ], 422);
        }

        $alreadyBought = MarketplaceItemInventory::select(['id'])
            ->where('item_id', $item->id)
            ->where('user_id', $user->id)
            ->exists();
        if ($alreadyBought) {
            return response()->json([
                'message' => 'You already own this item',
            ], 422);
        }

        if ($item->is_limited && $item->stock_left <= 0) {
            return response()->json([
                'message' => 'This item has 0 stock left',
            ], 422);
        }

        if ($user->coins < $item->price) {
            return response()->json([
                'message' => 'You do not have enough Coins to buy this',
            ], 422);
        }

        $inventory = MarketplaceItemInventory::create([
            'item_id' => $item->id,
            'user_id' => $user->id,
            'serial' => $item->sold_count + 1,
            'price' => $item->price,
        ]);

        $user->coins = $user->coins - $item->price;
        $user->save();

        $user->recalculateStats();

        $creator = User::select(['id', 'coins'])->find($item->user_id);
        $creator->coins = $creator->coins + $item->price;
        $creator->save();

        QuestController::incrementProgress($creator->id, 'Sell Items on Marketplace', 1);
        QuestController::incrementProgress($creator->id, 'Marketplace Tycoon', 1);

        if ($item->is_limited) {
            $item->stock_left = $item->stock_left - 1;
            $item->save();
        }

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "inventory:user:{$user->id}:%")
            ->delete();

        return response()->json([
            'message' => 'Successfully bought item!',
        ], 200);
    }

    public function caseContents($id)
    {
        $category = Cache::remember("marketplace:category:for:item:{$id}", 3600, function () use ($id) {
            return MarketplaceItem::select(['id', 'category_id'])
                ->where('id', $id)
                ->where('is_deleted', false)
                ->with('category:id,title')
                ->first()->toArray();
        });

        if (! $category) {
            return response()->json([
                'message' => 'Item not found',
            ], 404);
        }

        if ($category->category->title != 'Boxes') {
            return response()->json([
                'message' => 'This item is not a box.',
            ], 422);
        }

        $items = Cache::remember("marketplace:case:{$id}:contents", 120, function () use ($id) {
            return MarketplaceCaseContent::select(['id', 'case_id', 'item_id'])
                ->where('case_id', $id)
                ->with('item:id,title,texture_path')
                ->get()->toArray();
        });

        return response()->json(['data' => $items], 200);
    }

    public function owners($id)
    {
        $item = MarketplaceItem::select(['id', 'is_deleted'])
            ->where('id', $id)
            ->where('is_deleted', false)
            ->first();

        if (! $item) {
            return response()->json([
                'message' => 'Item not found',
            ], 404);
        }

        $page = request()->query('page', 1);
        $owners = Cache::remember("marketplace:item:{$id}:owners:page:{$page}", 60, function () use ($id) {
            return MarketplaceItemInventory::select(['id', 'item_id', 'user_id', 'serial'])
                ->where('item_id', $id)
                ->with('user:id,username')
                ->paginate(5)
                ->toArray();
        });

        return response()->json($owners, 200);
    }

    public function sellRequests($id)
    {
        $item = MarketplaceItem::select(['id', 'is_deleted'])
            ->where('id', $id)
            ->where('is_deleted', false)
            ->first();

        if (! $item) {
            return response()->json([
                'message' => 'Item not found',
            ], 404);
        }

        $page = request()->query('page', 1);
        $requests = Cache::remember("marketplace:item:{$id}:sell_requests:page:{$page}", 60, function () use ($id) {
            return MarketplaceSellRequest::select(['id', 'item_id', 'user_id', 'inventory_id', 'price', 'created_at'])
                ->where('item_id', $id)
                ->with('inventory:id,item_id,user_id,serial')
                ->with('user:id,username')
                ->paginate(5)
                ->toArray();
        });

        return response()->json($requests, 200);
    }

    public function createSellRequest($id, CreateSellRequest $request)
    {
        $data = $request->validated();
        $item = MarketplaceItem::select(['id', 'is_deleted'])
            ->where('id', $id)
            ->where('is_deleted', false)
            ->first();

        if (! $item) {
            return response()->json([
                'message' => 'Item not found',
            ], 404);
        }

        $user = app('token_user');
        $inventory = MarketplaceItemInventory::select(['id', 'item_id', 'user_id'])
            ->where('item_id', $item->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $inventory) {
            return response()->json([
                'message' => 'You do not own this item',
            ], 422);
        }

        if (MarketplaceSellRequest::where('inventory_id', $inventory->id)->exists()) {
            return response()->json([
                'message' => 'You are already selling this item',
            ], 422);
        }

        $request = MarketplaceSellRequest::create([
            'item_id' => $item->id,
            'user_id' => $user->id,
            'inventory_id' => $inventory->id,
            'price' => $data['price'],
        ]);

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "marketplace:item:{$request->item_id}:sell_requests:page:%")
            ->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "marketplace:item:{$request->item_id}:owners:page:%")
            ->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "inventory:user:{$user->id}:%")
            ->delete();

        return response()->json([
            'data' => 'success',
        ], 201);
    }

    public function deleteSellRequest($id)
    {
        $request = MarketplaceSellRequest::select(['id', 'user_id'])
            ->where('id', $id)
            ->first();

        if (! $request) {
            return response()->json([
                'message' => 'Request not found',
            ], 404);
        }

        $user = app('token_user');

        if ($request->user_id !== $user->id) {
            return response()->json([
                'message' => 'You can only delete your own sell requests',
            ], 403);
        }

        $request->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "marketplace:item:{$request->item_id}:sell_requests:page:%")
            ->delete();

        return response()->json([
            'data' => 'success',
        ]);
    }

    public function acceptSellRequest($id)
    {
        $request = MarketplaceSellRequest::select(['id', 'item_id', 'user_id', 'inventory_id', 'price'])
            ->with('user:id,coins')
            ->where('id', $id)
            ->first();

        if (! $request) {
            return response()->json([
                'message' => 'Request not found',
            ], 404);
        }

        $user = app('token_user');
        $inventory = MarketplaceItemInventory::select(['id', 'item_id', 'user_id', 'serial'])
            ->where('id', $request->inventory_id)
            ->where('user_id', $request->user_id)
            ->where('item_id', $request->item_id)
            ->first();
        if (! $inventory) {
            $request->delete();

            return response()->json([
                'message' => 'Invalid request',
            ], 422);
        }

        if ($request->user_id == $user->id) {
            $request->delete();

            return response()->json([
                'message' => 'Cannot buy from yourself',
            ], 422);
        }

        if ($user->coins < $request->price) {
            return response()->json([
                'message' => 'You do not have enough coins',
            ], 422);
        }

        $user->coins = $user->coins - $request->price;
        $user->save();

        $request->user->coins = $request->user->coins + $request->price;
        $request->user->save();

        
        $inventory->user_id = $user->id;
        $inventory->save();

        $request->user->recalculateStats();
        $user->recalculateStats();
        
        $request->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "marketplace:item:{$request->item_id}:sell_requests:page:%")
            ->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "inventory:user:{$user->id}:%")
            ->delete();

        DB::table('cache')
            ->where('key', 'like', config('cache.prefix', '') . "inventory:user:{$request->user->id}:%")
            ->delete();

        QuestController::incrementProgress($request->user->id, 'Sell Items on Marketplace', 1);
        QuestController::incrementProgress($request->user->id, 'Marketplace Tycoon', 1);

        MarketplaceSellRequestHistory::create([
            'from_id' => $request->user_id,
            'to_id' => $user->id,
            'price' => $request->price,
            'serial' => $inventory->serial,
            'item_id' => $inventory->item_id,
        ]);

        return response()->json([
            'data' => 'success',
        ]);
    }
}
