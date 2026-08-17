<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use App\Models\ArenaItem;
use App\Models\ArenaMove;
use App\Models\UserToken;
use Illuminate\Http\Request;

class ArenaItemController extends Controller
{
    private function getAdmin()
    {
        $token = str_replace('Bearer ', '', request()->header('Authorization'));
        $userToken = UserToken::where('token', $token)->first();

        return $userToken ? $userToken->user : null;
    }

    public function index(Request $request)
    {
        $query = $request->query('query', '');
        $page = $request->query('page', 1);

        $items = ArenaItem::with(['item', 'moves'])
            ->when($query !== '', function ($q) use ($query) {
                $q->whereHas('item', function ($item) use ($query) {
                    $item->where('title', 'like', "%{$query}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json($items, 200);
    }

    public function store(Request $request)
    {
        $admin = $this->getAdmin();

        $data = $request->validate([
            'item_id' => ['required', 'exists:marketplace_items,id'],
            'attack' => ['required', 'integer', 'min:0', 'max:10000'],
            'defense' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        if (ArenaItem::where('item_id', $data['item_id'])->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'That item is already registered as arena-compatible.',
            ], 422);
        }

        $arenaItem = ArenaItem::create($data);

        if ($admin) {
            AdminLog::create([
                'admin_id' => $admin->id,
                'target_id' => 0,
                'log' => 'Added arena item #'.$arenaItem->id.' (item #'.$data['item_id'].", ATK {$data['attack']} / DEF {$data['defense']})",
            ]);
        }

        return response()->json(['data' => $arenaItem->load(['item', 'moves'])], 201);
    }

    public function update(Request $request, $id)
    {
        $admin = $this->getAdmin();
        $arenaItem = ArenaItem::find($id);

        if (! $arenaItem) {
            return response()->json(['status' => 'error', 'message' => 'Arena item not found'], 404);
        }

        $data = $request->validate([
            'attack' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'defense' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ]);

        $arenaItem->update($data);

        if ($admin) {
            AdminLog::create([
                'admin_id' => $admin->id,
                'target_id' => 0,
                'log' => 'Updated arena item #'.$arenaItem->id.' (item #'.$arenaItem->item_id.', ATK '.$arenaItem->attack.' / DEF '.$arenaItem->defense.')',
            ]);
        }

        return response()->json(['data' => $arenaItem->load(['item', 'moves'])], 200);
    }

    /**
     * Save the two moves (name, damage, border color) configured for an
     * arena item. Each slot is upserted so the admin form is idempotent.
     */
    public function storeMoves(Request $request, $id)
    {
        $admin = $this->getAdmin();
        $arenaItem = ArenaItem::find($id);

        if (! $arenaItem) {
            return response()->json(['status' => 'error', 'message' => 'Arena item not found'], 404);
        }

        $data = $request->validate([
            'moves' => ['array', 'max:2'],
            'moves.*.position' => ['required', 'integer', 'between:1,2'],
            'moves.*.name' => ['required', 'string', 'max:50'],
            'moves.*.damage' => ['required', 'integer', 'min:1', 'max:10000'],
            'moves.*.cooldown' => ['nullable', 'integer', 'min:0', 'max:30'],
            'moves.*.border_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $moves = $data['moves'] ?? [];
        $positions = [];

        foreach ($moves as $move) {
            $positions[] = (int) $move['position'];
            ArenaMove::updateOrCreate(
                ['arena_item_id' => $arenaItem->id, 'position' => (int) $move['position']],
                [
                    'name' => $move['name'],
                    'damage' => (int) $move['damage'],
                    'cooldown' => (int) ($move['cooldown'] ?? 0),
                    'border_color' => $move['border_color'],
                ]
            );
        }

        // Drop any moves whose slot is no longer part of the submitted set.
        ArenaMove::where('arena_item_id', $arenaItem->id)
            ->whereNotIn('position', $positions)
            ->delete();

        if ($admin) {
            AdminLog::create([
                'admin_id' => $admin->id,
                'target_id' => 0,
                'log' => 'Updated moves for arena item #'.$arenaItem->id.' (item #'.$arenaItem->item_id.')',
            ]);
        }

        return response()->json(['data' => $arenaItem->load(['item', 'moves'])], 200);
    }

    public function destroy($id)
    {
        $admin = $this->getAdmin();
        $arenaItem = ArenaItem::find($id);

        if (! $arenaItem) {
            return response()->json(['status' => 'error', 'message' => 'Arena item not found'], 404);
        }

        $arenaItem->delete();

        if ($admin) {
            AdminLog::create([
                'admin_id' => $admin->id,
                'target_id' => 0,
                'log' => 'Removed arena item #'.$id,
            ]);
        }

        return response()->json(['status' => 'ok'], 200);
    }
}
