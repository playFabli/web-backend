<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceItemInventory;
use App\Models\UserProfileItem;
use Illuminate\Support\Facades\Cache;

class ProfileController extends Controller
{
    public function items($userId)
    {
        $profileItems = UserProfileItem::where('user_id', $userId)
            ->with('item:id,title,texture_path,price,rap,rarity,category_id')
            ->with('item.category:id,title')
            ->orderBy('sort_order')
            ->get()
            ->map(function ($profileItem) use ($userId) {
                $data = $profileItem->toArray();
                // Get serial from the user's inventory for this item
                $inventory = MarketplaceItemInventory::select('serial')
                    ->where('user_id', $userId)
                    ->where('item_id', $profileItem->item_id)
                    ->first();
                $data['serial'] = $inventory?->serial;

                return $data;
            });

        return response()->json([
            'data' => $profileItems,
        ], 200);
    }

    public function saveItems($userId)
    {
        $currentUser = app('token_user');

        if ($currentUser->id !== (int) $userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 403);
        }

        $items = request()->input('items', []);

        UserProfileItem::where('user_id', $userId)->delete();

        foreach ($items as $index => $itemId) {
            UserProfileItem::create([
                'user_id' => $userId,
                'item_id' => $itemId,
                'sort_order' => $index,
            ]);
        }

        return response()->json(['status' => 'success'], 200);
    }

    public function availableItems($userId)
    {
        $currentUser = app('token_user');

        if ($currentUser->id !== (int) $userId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 403);
        }

        $search = request()->query('search', '');
        $category = request()->query('category', '');

        $profileItemIds = UserProfileItem::where('user_id', $userId)
            ->pluck('item_id')
            ->toArray();

        $itemsQuery = MarketplaceItem::select([
            'id', 'title', 'texture_path', 'price', 'rap', 'rarity', 'category_id',
        ])
            ->where('moderation_status', 'approved')
            ->whereHas('inventories', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            });

        if ($search) {
            $itemsQuery->where('title', 'like', "%{$search}%");
        }

        if ($category) {
            $itemsQuery->whereHas('category', function ($query) use ($category) {
                $query->where('title', $category);
            });
        }

        $items = $itemsQuery->get()->map(function ($item) use ($profileItemIds) {
            return [
                'id' => $item->id,
                'title' => $item->title,
                'texture_path' => $item->texture_path,
                'price' => $item->price,
                'rap' => $item->rap,
                'rarity' => $item->rarity,
                'category_id' => $item->category_id,
                'category_title' => $item->category->title ?? '',
                'on_wall' => in_array($item->id, $profileItemIds),
            ];
        });

        return response()->json([
            'data' => $items,
        ], 200);
    }

    public function categories()
    {
        $categories = Cache::remember('marketplace:categories:all:0', 3600, function () {
            return MarketplaceCategory::orderBy('sort_index')
                ->orderBy('id')
                ->get(['id', 'title'])
                ->toArray();
        });

        return response()->json([
            'data' => $categories,
        ], 200);
    }
}
