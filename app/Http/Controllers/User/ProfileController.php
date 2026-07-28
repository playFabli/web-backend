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

    /**
     * Get the user's owned customization items (themes and avatar frames).
     */
    public function customization()
    {
        $user = app('token_user');

        // Find category IDs for "Profile Themes" and "Avatar Frames" by title
        $themeCategory = MarketplaceCategory::where('title', 'Profile Themes')->first();
        $frameCategory = MarketplaceCategory::where('title', 'Avatar Frames')->first();

        $themes = collect();
        $frames = collect();

        // Get owned items in the Profile Themes category
        if ($themeCategory) {
            $themes = MarketplaceItem::select(['id', 'title', 'texture_path', 'price', 'rap', 'rarity', 'stylesheet_path'])
                ->where('category_id', $themeCategory->id)
                ->where('moderation_status', 'approved')
                ->whereHas('inventories', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->get();
        }

        // Get owned items in the Avatar Frames category
        if ($frameCategory) {
            $frames = MarketplaceItem::select(['id', 'title', 'texture_path', 'price', 'rap', 'rarity'])
                ->where('category_id', $frameCategory->id)
                ->where('moderation_status', 'approved')
                ->whereHas('inventories', function ($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->get();
        }

        return response()->json([
            'data' => [
                'profile_theme_id' => $user->profile_theme_id,
                'avatar_frame_id' => $user->avatar_frame_id,
                'themes' => $themes,
                'frames' => $frames,
            ],
        ], 200);
    }

    /**
     * Save the user's customization selections (theme and avatar frame).
     */
    public function saveCustomization()
    {
        $user = app('token_user');

        $profileThemeId = (int) request()->input('profile_theme_id', 0);
        $avatarFrameId = (int) request()->input('avatar_frame_id', 0);

        // Validate that the user owns the selected items (if not default 0)
        if ($profileThemeId > 0) {
            $ownsTheme = MarketplaceItemInventory::where('user_id', $user->id)
                ->where('item_id', $profileThemeId)
                ->exists();

            if (! $ownsTheme) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not own the selected profile theme.',
                ], 422);
            }
        }

        if ($avatarFrameId > 0) {
            $ownsFrame = MarketplaceItemInventory::where('user_id', $user->id)
                ->where('item_id', $avatarFrameId)
                ->exists();

            if (! $ownsFrame) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not own the selected avatar frame.',
                ], 422);
            }
        }

        $user->profile_theme_id = $profileThemeId;
        $user->avatar_frame_id = $avatarFrameId;
        $user->save();

        return response()->json([
            'status' => 'success',
            'data' => [
                'profile_theme_id' => $user->profile_theme_id,
                'avatar_frame_id' => $user->avatar_frame_id,
            ],
        ], 200);
    }
}
