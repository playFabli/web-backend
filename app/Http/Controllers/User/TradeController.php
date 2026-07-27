<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Trades\CreateTradeRequest;
use App\Models\MarketplaceItemInventory;
use App\Models\Trade;
use App\Models\User;

class TradeController extends Controller
{
    public function trades($tab = 0)
    {
        $user = app('token_user');
        if ($tab == 0) {
            $trades = Trade::where(function ($query) use ($user) {
                $query->where('to_id', $user->id)
                    ->orWhere('from_id', $user->id);
            })->where('status', 0)->with('from')->with('to')->paginate(8);

            return response()->json($trades);
        } elseif ($tab == 1) {
            $trades = Trade::where(function ($query) use ($user) {
                $query->where('to_id', $user->id)
                    ->orWhere('from_id', $user->id);
            })->where('status', '!=', 0)->with('from')->with('to')->paginate(8);

            return response()->json($trades);
        }

        return response()->json([], 200);
    }

    public function create($toId, CreateTradeRequest $request)
    {
        $data = $request->validated();
        $user = app('token_user');

        if (! $user->is_email_verified) {
            return response()->json([
                'message' => 'Contact support',
            ], 422);
        }

        if ($toId == $user->id) {
            return response()->json([
                'message' => 'You cannot trade with yourself',
            ], 422);
        }

        $to = User::where('id', $toId)->first();
        if (! $to) {
            return response()->json([
                'message' => 'User not found',
            ], 404);
        }

        if ($user->coins < $data['offering_coins']) {
            return response()->json([
                'message' => 'You do not have enough coins',
            ], 422);
        }

        if ($to->coins < $data['receiving_coins']) {
            return response()->json([
                'message' => 'The user you are trading with does not have enough coins',
            ], 422);
        }

        $offeringArr = $data['offering'];
        foreach ($offeringArr as $offer) {
            $inventory = MarketplaceItemInventory::where('id', $offer)->first();
            if (! $inventory) {
                return response()->json([
                    'message' => 'Invalid offering',
                ], 422);
            }

            if ($inventory->user_id != $user->id) {
                return response()->json([
                    'message' => 'Invalid offering',
                ], 422);
            }
        }

        $requestingArr = $data['receiving'];
        foreach ($requestingArr as $request) {
            $inventory = MarketplaceItemInventory::where('id', $request)->first();
            if (! $inventory) {
                return response()->json([
                    'message' => 'Invalid requesting',
                ], 422);
            }

            if ($inventory->user_id != $to->id) {
                return response()->json([
                    'message' => 'Invalid requesting',
                ], 422);
            }
        }

        $trade = new Trade;
        $trade->from_id = $user->id;
        $trade->to_id = $toId;
        $trade->offering_csv = implode(';', $data['offering']);
        $trade->requesting_csv = implode(';', $data['receiving']);
        $trade->offering_coins = $data['offering_coins'];
        $trade->requesting_coins = $data['receiving_coins'];
        $trade->status = 0;
        $trade->save();

        return response()->json([], 201);
    }

    public function changeTradeState($id, $status)
    {
        $trade = Trade::where('id', $id)->first();
        if (! $trade) {
            return response()->json([
                'message' => 'Trade not found',
            ], 404);
        }

        $user = app('token_user');
        if ($trade->to_id != $user->id) {
            return response()->json([
                'message' => 'You cannot change the state of this trade',
            ], 403);
        }

        if (! $user->is_email_verified) {
            return response()->json([
                'message' => 'Contact support',
            ], 422);
        }

        if ($trade->status != 0) {
            return response()->json([
                'message' => 'This trade has already been modified',
            ], 403);
        }

        if ($status == 1) {
            $from = User::where('id', $trade->from_id)->first();

            if ($from->coins < $trade->offering_coins) {
                $trade->status = 2;

                return response()->json([
                    'message' => 'The user you are trading with does not have enough coins',
                ], 422);
            }

            if ($user->coins < $trade->requesting_coins) {
                $trade->status = 2;

                return response()->json([
                    'message' => 'You do not have enough coins',
                ], 422);
            }

            if (strlen($trade->offering_csv) > 0) {
                $offeringArr = explode(';', $trade->offering_csv);
                foreach ($offeringArr as $offer) {
                    $inventory = MarketplaceItemInventory::where('id', $offer)->first();
                    if ($inventory && $inventory->user_id === $from->id) {
                        $inventory->user_id = $user->id;
                        $inventory->save();
                    } else {
                        $trade->status = 2;
                        $trade->save();

                        return response()->json([
                            'message' => "User doesn't own one of these items anymore",
                        ], 422);
                    }
                }
            }

            if (strlen($trade->requesting_csv) > 0) {
                $requestingArr = explode(';', $trade->requesting_csv);
                foreach ($requestingArr as $request) {
                    $inventory = MarketplaceItemInventory::where('id', $request)->first();
                    if ($inventory && $inventory->user_id === $user->id) {
                        $inventory->user_id = $from->id;
                        $inventory->save();
                    } else {
                        $trade->status = 2;
                        $trade->save();

                        return response()->json([
                            'message' => "User doesn't own one of these items anymore",
                        ], 422);
                    }
                }
            }

            $from->coins = $from->coins - $trade->offering_coins;
            $user->coins = $user->coins - $trade->requesting_coins;
            $from->save();
            $user->save();

            $from->recalculateStats();
            $user->recalculateStats();

            $trade->status = 1;
            $trade->save();

            $todayStart = now()->startOfDay();

            $fromHasTradedWithUserToday = Trade::where('from_id', $from->id)
                ->where('to_id', $user->id)
                ->where('status', 1)
                ->where('created_at', '>=', $todayStart)
                ->exists();

            $userHasTradedWithFromToday = Trade::where('from_id', $user->id)
                ->where('to_id', $from->id)
                ->where('status', 1)
                ->where('created_at', '>=', $todayStart)
                ->exists();

            if (! $fromHasTradedWithUserToday) {
                QuestController::incrementProgress($from->id, 'Trade Items', 1);
                QuestController::incrementProgress($from->id, 'Trade Volume', 1);
            }

            if (! $userHasTradedWithFromToday) {
                QuestController::incrementProgress($user->id, 'Trade Items', 1);
                QuestController::incrementProgress($user->id, 'Trade Volume', 1);
            }

            return response()->json([], 200);
        } else {
            $trade->status = 2;
            $trade->save();

            return response()->json([], 200);
        }
    }
}
