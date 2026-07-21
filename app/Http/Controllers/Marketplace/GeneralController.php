<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Helpers\PythonRenderHelper;
use App\Http\Requests\Marketplace\CommentRequest;
use App\Http\Requests\Marketplace\CreateItemRequest;
use App\Http\Requests\Marketplace\CreateSellRequest;
use App\Models\MarketplaceCaseContent;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceItemInventory;
use App\Models\MarketplaceSellRequest;
use App\Models\MarketplaceSellRequestHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GeneralController extends Controller
{
    public function categories($all=1) {

        if(!$all) {
            $categories = \App\Models\MarketplaceCategory::where("is_admin_only", 0)->get();
        } else {
            $categories = \App\Models\MarketplaceCategory::all();
        }
        return response()->json([
            "data" => $categories, $all
        ], 200);
    }

    public function create(CreateItemRequest $request) {
        $data = $request->validated();
        $user = app("token_user");

        $texturePath = null;
        if ($request->hasFile('texture')) {
            $file = $request->file('texture');
            $filename = time();
            $storageDir = rtrim(config('app.renderer_directory'), "/\\") . DIRECTORY_SEPARATOR . 'textures';
            $moved = $file->move($storageDir, $filename . ".png");
            if ($moved) {
                $texturePath = 'textures/' . $filename . ".png";
            }
        }

        $item = MarketplaceItem::create([
            "user_id" => $user->id,
            "category_id" => $data["category_id"],
            "title" => $data["title"],
            "description" => $data["description"],
            "texture_path" => $texturePath,
            "price" => $data["price"],
            "moderation_status" => "pending",
        ]);


        $inventory = new MarketplaceItemInventory();
        $inventory->item_id = $item->id;
        $inventory->user_id = $user->id;
        $inventory->serial = 1;
        $inventory->price = 0;
        $inventory->save();

        // Render the item thumbnail
        $this->renderItem($item);

        return response()->json([
            "data" => $item
        ], 201);
    }

    public function renderItem($item) {
        $category = $item->category;
        
        $renderer = new PythonRenderHelper();
        $renderer->loadBlend(config("app.renderer_directory") . "/scene.blend");

        // All colors should be pure white for item rendering
        $whiteColor = "#FFFFFF";

        // Load the 3D model if the category has one
        if ($category->has_model) {
            $modelPath = config("app.renderer_directory") . "/models/" . $item->id;
            $renderer->loadObj($item->id, $modelPath, $category->has_texture);
        }

        // Apply texture if the category has one and the item has a texture path
        if ($category->has_texture && $item->texture_path) {
            $parts = $category->parts_affected_array;
            $texturePath = config("app.renderer_directory") . "/" . $item->texture_path;
            foreach ($parts as $part) {
                $renderer->addTexture($part, $item->id . "_tex", $texturePath);
            }
        }

        // Add face
        $renderer->addTexture("head", "default_face", config("app.renderer_directory") . "/textures/def_face.png");

        // Apply white colors to all parts
        $parts = ["head", "left_arm", "right_arm", "torso", "right_leg", "left_leg"];
        foreach($parts as $part) {
            $renderer->selectAndColor($part, $whiteColor);
        }

        // Generate a unique hash for the render
        $hash = md5($item->id . time());
        $outputPath = config("app.storage_directory") . "/items";

        $renderer->focus(['all']);
        $renderer->save($item->id, $outputPath);

        $script = $renderer->getScript();
        if (file_put_contents(config('app.renderer_directory')."/python/$hash.py", $script)) {
            $output = [];
            $code = 0;
            exec("blender279 -b -P ".config('app.renderer_directory')."/python/$hash.py 2>&1", $output, $code);

            return response()->json([
                "data" => [
                    "render_url" => "/{$item->id}.png",
                    "hash" => $hash,
                    "output" => $output,
                    "code" => $code
                ]]);
        }

        return response()->json(["error" => "Failed to render item"], 500);
    }

    public function items($categories = "1,2,3,4,5") {
        $categories = explode(",", $categories);

        $priceMin = request()->query("price_min", 0);
        $priceMax = request()->query("price_max", 999999999);

        $rapMin = request()->query("rap_min", 0);
        $rapMax = request()->query("rap_max", 999999999);

        if($priceMax == "null") {
            $priceMax = 999999999;
        }

        if($rapMax == "null") {
            $rapMax = 999999999;
        }

        $query = request()->query("query", "");

        $items = \App\Models\MarketplaceItem::whereIn("category_id", $categories)
            ->where("is_deleted", false)
            ->where("moderation_status", "approved")
            ->whereBetween("price", [$priceMin, $priceMax])
            ->whereBetween("rap", [$rapMin, $rapMax])
            ->where(function($q) use ($query) {
                $q->where("title", "like", "%$query%")
                  ->orWhere("description", "like", "%$query%");
            })
            ->with("user")
            ->with("category")
            ->orderBy("created_at", "desc")
            ->paginate(12);

        // Track quest progress for visiting marketplace
        $user = app("token_user");
        \App\Http\Controllers\User\QuestController::incrementProgress($user->id, "Visit Marketplace", 1);

        return response()->json($items, 200);
    }

    public function item($id) {
        $item = \App\Models\MarketplaceItem::where("id", $id)
            ->where("is_deleted", false)
            ->with("user")
            ->with("category")
            ->with(["comments" => function($query) {
                $query->orderBy("created_at", "desc");
            }, "comments.user"])
            ->first();

        if(!$item) {
            return response()->json([
                "error" => "Item not found"
            ], 404);
        }

        return response()->json([
            "data" => $item
        ], 200);
    }

    public function comments($id) {
        $item = \App\Models\MarketplaceItem::where("id", $id)
            ->where("is_deleted", false)
            ->first();

        if(!$item) {
            return response()->json([
                "error" => "Item not found"
            ], 404);
        }

        $comments = $item->comments()->with("user")->orderBy("created_at", "desc")->get();
        return response()->json($comments, 200);
    }

    public function comment($id, CommentRequest $request) {
        $data = $request->validated();
        $item = \App\Models\MarketplaceItem::where("id", $id)
            ->where("is_deleted", false)
            ->first();

        if(!$item) {
            return response()->json([
                "error" => "Item not found"
            ], 404);
        }

        $user = app("token_user");

        $comment = \App\Models\MarketplaceComment::create([
            "item_id" => $item->id,
            "user_id" => $user->id,
            "content" => $data["content"]
        ]);

        return response()->json($comment, 201);
    }

    public function owns($id) {
        $item = \App\Models\MarketplaceItem::where("id", $id)
            ->where("is_deleted", false)
            ->first();

        if(!$item) {
            return response()->json([
                "message" => "Item not found"
            ], 404);
        }

        $user = app("token_user");

        $alreadyBought = MarketplaceItemInventory::where("item_id", $item->id)->where("user_id", $user->id)->get();
        return response()->json([
            "bool" => (count($alreadyBought) > 0),
            "data" => $alreadyBought
        ]);
    }

    public function buy($id) {
        $item = \App\Models\MarketplaceItem::where("id", $id)
            ->where("is_deleted", false)
            ->first();

        if(!$item) {
            return response()->json([
                "message" => "Item not found"
            ], 404);
        }

        $user = app("token_user");

        if($item->user_id === $user->id) {
            return response()->json([
                "message" => "Cannot buy your own item"
            ], 422);
        }

        $alreadyBought = MarketplaceItemInventory::where("item_id", $item->id)->where("user_id", $user->id)->exists();
        if($alreadyBought) {
            return response()->json([
                "message" => "You already own this item"
            ], 422);        
        }

        if($item->is_limited && $item->stock_left <= 0) {
            return response()->json([
                "message" => "This item has 0 stock left"
            ], 422);        
        }

        if($user->coins < $item->price) {
            return response()->json([
                "message" => "You do not have enough Coins to buy this"
            ], 422);        
        }

        $inventory = MarketplaceItemInventory::create([
            "item_id"=>$item->id,
            "user_id"=>$user->id,
            "serial"=>$item->sold_count+1,
            "price"=>$item->price
        ]);

        $user->coins = $user->coins - $item->price;
        $user->save();

        if($item->is_limited) {
            $item->stock_left = $item->stock_left - 1;
            $item->save();
        }

        return response()->json([
            "message"=>"Successfully bought item!"
        ], 200);
    }

    public function caseContents($id) {
        $item = \App\Models\MarketplaceItem::where("id", $id)
            ->where("is_deleted", false)
            ->first();

        if(!$item) {
            return response()->json([
                "message" => "Item not found"
            ], 404);
        }

        if($item->category->title != "Boxes") {
            return response()->json([
                "message" => "This item is not a box."
            ], 422);
        }

        $items = MarketplaceCaseContent::where("case_id", $item->id)->with("item")->get();

        return response()->json(["data"=>$items], 200);
    }

    public function owners($id) {
        $item = \App\Models\MarketplaceItem::where("id", $id)
            ->where("is_deleted", false)
            ->first();

        if(!$item) {
            return response()->json([
                "message" => "Item not found"
            ], 404);
        }

        $owners = MarketplaceItemInventory::where("item_id", $item->id)->with("user")->paginate(5);

        return response()->json($owners, 200);
    }

    public function sellRequests($id) {
        $item = \App\Models\MarketplaceItem::where("id", $id)
            ->where("is_deleted", false)
            ->first();

        if(!$item) {
            return response()->json([
                "message" => "Item not found"
            ], 404);
        }

        $requests = MarketplaceSellRequest::where("item_id", $item->id)->with("inventory")->with("user")->paginate(5);

        return response()->json($requests, 200);
    }

    public function createSellRequest($id, CreateSellRequest $request) {
        $data = $request->validated();
        $item = \App\Models\MarketplaceItem::where("id", $id)
            ->where("is_deleted", false)
            ->first();

        if(!$item) {
            return response()->json([
                "message" => "Item not found"
            ], 404);
        }

        $user = app("token_user");
        $inventory = MarketplaceItemInventory::where("item_id", $item->id)->where("user_id", $user->id)->first();

        if(!$inventory) {
            return response()->json([
                "message" => "You do not own this item"
            ], 422);
        }

        // is already selling?
        if(MarketplaceSellRequest::where("inventory_id", $inventory->id)->exists()) {
            return response()->json([
                "message" => "You are already selling this item"
            ], 422);
        }

        $request = MarketplaceSellRequest::create([
            "item_id"=>$item->id,
            "user_id"=>$user->id,
            "inventory_id"=>$inventory->id,
            "price"=>$data["price"]
        ]);

        return response()->json([
            "data"=>"success"
        ], 201);

    }

    public function acceptSellRequest($id) {
        $request = MarketplaceSellRequest::where("id", $id)->first();

        if(!$request) {
            return response()->json([
                "message" => "Request not found"
            ], 404);
        }

        $user = app("token_user");
        $inventory = MarketplaceItemInventory::where("id", $request->inventory_id)->where("user_id", $request->user_id)->where("item_id", $request->item_id)->first();
        if(!$inventory) {
            $request->delete();
            return response()->json([
                "message" => "Invalid request"
            ], 422);
        }

        if($request->user_id == $user->id) {
            $request->delete();
            return response()->json([
                "message" => "Cannot buy from yourself"
            ], 422);
        }

        if($user->coins < $request->price) {
            return response()->json([
                "message" => "You do not have enough coins"
            ], 422);
        }

        $user->coins = $user->coins - $request->price;
        $user->save();

        $request->user->coins = $request->user->coins + $request->price;
        $request->user->save();

        $inventory->user_id = $user->id;
        $inventory->save();

        $request->delete();

        // Track quest progress for selling items
        \App\Http\Controllers\User\QuestController::incrementProgress($request->user->id, "Sell Items on Marketplace", 1);
        \App\Http\Controllers\User\QuestController::incrementProgress($request->user->id, "Marketplace Tycoon", 1);

        MarketplaceSellRequestHistory::create([
            "from_id"=>$request->user_id,
            "to_id"=>$user->id,
            "price"=>$request->price,
            "serial"=>$inventory->serial,
            "item_id"=>$inventory->item_id
        ]);

        return response()->json([
            "data"=>"success"
        ]);
    }

    
}