<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * List all transactions
     * GET /admin/v1/transactions
     */
    public function index(Request $request)
    {
        $query = Transaction::with(['user', 'wallet']);

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('txn_type')) {
            $query->where('txn_type', $request->get('txn_type'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        if ($request->has('source_table')) {
            $query->where('source_table', $request->get('source_table'));
        }

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->get('from_date'));
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->get('to_date'));
        }

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where('transaction_code', 'like', "%{$search}%");
        }

        $perPage = $request->get('per_page', 15);
        $transactions = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'transactions' => $transactions->items(),
                'pagination' => [
                    'page' => $transactions->currentPage(),
                    'limit' => $transactions->perPage(),
                    'total' => $transactions->total(),
                    'totalPages' => $transactions->lastPage(),
                ]
            ]
        ]);
    }

    /**
     * Get transaction statistics
     * GET /admin/v1/transactions/stats
     */
    public function stats(Request $request)
    {
        $totalTransactions = Transaction::count();
        $totalCredits = Transaction::where('txn_type', 'credit')->sum('amount');
        $totalDebits = Transaction::where('txn_type', 'debit')->sum('amount');

        $todayCredits = Transaction::where('txn_type', 'credit')
            ->whereDate('created_at', today())
            ->sum('amount');
        $todayDebits = Transaction::where('txn_type', 'debit')
            ->whereDate('created_at', today())
            ->sum('amount');

        $bySource = Transaction::selectRaw('source_table, count(*) as count, sum(amount) as total')
            ->groupBy('source_table')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_transactions' => $totalTransactions,
                'total_credits' => $totalCredits,
                'total_debits' => $totalDebits,
                'net_flow' => $totalCredits - $totalDebits,
                'today_credits' => $todayCredits,
                'today_debits' => $todayDebits,
                'by_source' => $bySource,
            ]
        ]);
    }

    /**
     * Show transaction details
     * GET /admin/v1/transactions/{id}
     */
    public function show(string $id)
    {
        $transaction = Transaction::with(['user', 'wallet'])->find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        $sourceDetails = null;
        if ($transaction->source_table && $transaction->source_id) {
            $sourceModel = 'App\\Models\\' . ucfirst(str_replace('_', '', $transaction->source_table));
            if (class_exists($sourceModel)) {
                $sourceDetails = $sourceModel::find($transaction->source_id);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'transaction' => $transaction,
                'source_details' => $sourceDetails,
            ]
        ]);
    }

    /**
     * Reverse a transaction (admin only)
     * POST /admin/v1/transactions/{id}/reverse
     */
    public function reverse(Request $request, string $id)
    {
        $admin = $this->getAdmin();

        $transaction = Transaction::where('status', 'success')->find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found or cannot be reversed'], 404);
        }

        $transaction->update([
            'status' => 'reversed',
            'metadata' => array_merge($transaction->metadata ?? [], [
                'reversed_by' => $admin->id,
                'reversed_at' => now()->toDateTimeString(),
            ])
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transaction reversed',
            'data' => $transaction->fresh()
        ]);
    }

    private function getAdmin()
    {
        return auth('admin')->user();
    }
}
