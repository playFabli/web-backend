<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;

class PendingTransactionsController extends Controller
{
    public function usersWithPendingTransactions(Request $request)
    {
        $page = $request->query('page', 1);
        $perPage = 15;

        // Get users who have at least one pending transaction, with count
        $users = User::select(['id', 'username', 'role', 'created_at', 'last_seen_at'])
            ->whereHas('pendingTransactions')
            ->withCount('pendingTransactions')
            ->with(['pendingTransactions' => function ($query) {
                $query->select(['id', 'user_id', 'from_user_id', 'type', 'amount', 'status', 'created_at'])
                    ->with('fromUser:id,username')
                    ->orderBy('created_at', 'desc')
                    ->limit(5); // Show latest 5 pending transactions per user
            }])
            ->orderByRaw('(SELECT MAX(created_at) FROM transactions WHERE transactions.user_id = users.id AND status = ?)', ['pending'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json($users, 200);
    }
}
