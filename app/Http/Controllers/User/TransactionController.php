<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * List authenticated user's transactions
     * GET /user/v1/transactions
     */
    public function index(Request $request)
    {
        $user = auth('api')->user();

        $query = $user->transactions()->with('wallet');

        // Filter by type
        if ($request->has('type')) {
            $query->where('txn_type', $request->get('type'));
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        // Date range filter
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->get('from_date'));
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->get('to_date'));
        }

        $perPage = $request->get('per_page', 15);
        $transactions = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ]
        ]);
    }

    /**
     * Show transaction detail
     * GET /user/v1/transactions/{id}
     */
    public function show(string $id)
    {
        $user = auth('api')->user();

        $transaction = $user->transactions()->with('wallet')->find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        return response()->json([
            'data' => $transaction
        ]);
    }
}
