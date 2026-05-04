<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\Transaction;
use App\Models\WalletAccount;
use App\Models\WalletLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DepositController extends Controller
{
    /**
     * List all deposits
     * GET /admin/v1/deposits
     */
    public function index(Request $request)
    {
        $query = Deposit::with(['user', 'wallet']);

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        if ($request->has('payment_method')) {
            $query->where('payment_method', $request->get('payment_method'));
        }

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->get('from_date'));
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->get('to_date'));
        }

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('merchant_order_id', 'like', "%{$search}%")
                    ->orWhere('gateway_txn_id', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        $perPage = $request->get('per_page', 15);
        $deposits = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'deposits' => $deposits->items(),
                'pagination' => [
                    'page' => $deposits->currentPage(),
                    'limit' => $deposits->perPage(),
                    'total' => $deposits->total(),
                    'totalPages' => $deposits->lastPage(),
                ]
            ]
        ]);
    }

    /**
     * Get deposit statistics
     * GET /admin/v1/deposits/stats
     */
    public function stats(Request $request)
    {
        $totalDeposits = Deposit::count();
        $pendingDeposits = Deposit::where('status', 'pending')->count();
        $successfulDeposits = Deposit::where('status', 'success')->count();
        $failedDeposits = Deposit::where('status', 'failed')->count();

        $totalAmount = Deposit::where('status', 'success')->sum('amount');
        $todayAmount = Deposit::where('status', 'success')
            ->whereDate('created_at', today())
            ->sum('amount');

        return response()->json([
            'success' => true,
            'data' => [
                'total_deposits' => $totalDeposits,
                'pending_deposits' => $pendingDeposits,
                'successful_deposits' => $successfulDeposits,
                'failed_deposits' => $failedDeposits,
                'total_amount' => $totalAmount,
                'today_amount' => $todayAmount,
            ]
        ]);
    }

    /**
     * Show deposit details
     * GET /admin/v1/deposits/{id}
     */
    public function show(string $id)
    {
        $deposit = Deposit::with(['user', 'wallet'])->find($id);

        if (!$deposit) {
            return response()->json(['message' => 'Deposit not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $deposit
        ]);
    }

    /**
     * Approve a deposit
     * POST /admin/v1/deposits/{id}/approve
     */
    public function approve(Request $request, string $id)
    {
        $admin = $this->getAdmin();

        $deposit = Deposit::where('status', 'pending')->find($id);

        if (!$deposit) {
            return response()->json(['message' => 'Deposit not found or already processed'], 404);
        }

        try {
            return DB::transaction(function () use ($deposit, $admin, $request) {
                $wallet = WalletAccount::find($deposit->wallet_id);

                if (!$wallet) {
                    return response()->json(['message' => 'Wallet not found'], 404);
                }

                $balanceBefore = $wallet->available_balance;
                $wallet->available_balance += $deposit->amount;
                $wallet->save();

                WalletLedger::create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $deposit->user_id,
                    'txn_type' => 'deposit',
                    'direction' => 'credit',
                    'amount' => $deposit->amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $wallet->available_balance,
                    'reference_type' => 'deposit',
                    'reference_id' => $deposit->id,
                    'description' => 'Deposit approved by admin',
                ]);

                $deposit->update([
                    'status' => 'success',
                    'paid_at' => now(),
                    'metadata' => array_merge($deposit->metadata ?? [], [
                        'approved_by' => $admin->id,
                        'approved_at' => now()->toDateTimeString(),
                    ])
                ]);

                Transaction::create([
                    'user_id' => $deposit->user_id,
                    'wallet_id' => $wallet->id,
                    'transaction_code' => 'TXN-' . uniqid(),
                    'source_table' => 'deposits',
                    'source_id' => $deposit->id,
                    'amount' => $deposit->amount,
                    'txn_type' => 'credit',
                    'status' => 'success',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Deposit approved successfully',
                    'data' => $deposit->fresh()
                ]);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to approve deposit: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Reject a deposit
     * POST /admin/v1/deposits/{id}/reject
     */
    public function reject(Request $request, string $id)
    {
        $admin = $this->getAdmin();

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $deposit = Deposit::where('status', 'pending')->find($id);

        if (!$deposit) {
            return response()->json(['message' => 'Deposit not found or already processed'], 404);
        }

        $deposit->update([
            'status' => 'failed',
            'failed_reason' => $validator->validated()['reason'],
            'metadata' => array_merge($deposit->metadata ?? [], [
                'rejected_by' => $admin->id,
                'rejected_at' => now()->toDateTimeString(),
            ])
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Deposit rejected',
            'data' => $deposit->fresh()
        ]);
    }

    /**
     * Cancel a deposit
     * POST /admin/v1/deposits/{id}/cancel
     */
    public function cancel(string $id)
    {
        $admin = $this->getAdmin();

        $deposit = Deposit::whereIn('status', ['pending'])->find($id);

        if (!$deposit) {
            return response()->json(['message' => 'Deposit not found or cannot be cancelled'], 404);
        }

        $deposit->update([
            'status' => 'cancelled',
            'metadata' => array_merge($deposit->metadata ?? [], [
                'cancelled_by' => $admin->id,
                'cancelled_at' => now()->toDateTimeString(),
            ])
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Deposit cancelled',
            'data' => $deposit->fresh()
        ]);
    }

    private function getAdmin()
    {
        return auth('admin')->user();
    }
}
