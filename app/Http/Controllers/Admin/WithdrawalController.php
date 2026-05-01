<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Models\WalletAccount;
use App\Models\WalletLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WithdrawalController extends Controller
{
    /**
     * List all withdrawals with optional filters
     * GET /admin/v1/withdrawals
     */
    public function index(Request $request)
    {
        $query = Withdrawal::with(['user', 'wallet', 'approver']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        // Filter by user
        if ($request->has('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        // Filter by payout method
        if ($request->has('payout_method')) {
            $query->where('payout_method', $request->get('payout_method'));
        }

        // Search by user email/phone
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Date range filter
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->get('from_date'));
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->get('to_date'));
        }

        $perPage = $request->get('per_page', 15);
        $withdrawals = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $withdrawals->items(),
            'meta' => [
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
                'per_page' => $withdrawals->perPage(),
                'total' => $withdrawals->total(),
            ]
        ]);
    }

    /**
     * Show specific withdrawal details
     * GET /admin/v1/withdrawals/{id}
     */
    public function show(string $id)
    {
        $withdrawal = Withdrawal::with(['user', 'wallet', 'approver'])->find($id);

        if (!$withdrawal) {
            return response()->json(['message' => 'Withdrawal not found'], 404);
        }

        return response()->json([
            'data' => $withdrawal
        ]);
    }

    /**
     * Approve a withdrawal request
     * POST /admin/v1/withdrawals/{id}/approve
     */
    public function approve(Request $request, string $id)
    {
        $withdrawal = Withdrawal::find($id);

        if (!$withdrawal) {
            return response()->json(['message' => 'Withdrawal not found'], 404);
        }

        if ($withdrawal->status !== 'pending') {
            return response()->json(['message' => 'Only pending withdrawals can be approved'], 400);
        }

        $admin = auth('admin')->user();

        $validator = Validator::make($request->all(), [
            'gateway_name' => 'nullable|string',
            'gateway_ref_id' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $wallet = WalletAccount::find($withdrawal->wallet_id);
            
            if (!$wallet) {
                return response()->json(['message' => 'Wallet not found'], 404);
            }

            // Lock the amount from available balance
            if ($wallet->available_balance < $withdrawal->amount) {
                return response()->json(['message' => 'Insufficient wallet balance'], 400);
            }

            $balanceBefore = $wallet->available_balance;
            $wallet->available_balance -= $withdrawal->amount;
            $wallet->locked_balance += $withdrawal->amount;
            $wallet->save();

            // Update withdrawal status
            $withdrawal->status = 'approved';
            $withdrawal->approved_by = $admin->id;
            $withdrawal->approved_at = now();
            $withdrawal->gateway_name = $request->gateway_name;
            $withdrawal->gateway_ref_id = $request->gateway_ref_id;
            $withdrawal->metadata = array_merge($withdrawal->metadata ?? [], $request->metadata ?? []);
            $withdrawal->save();

            // Create ledger entry
            WalletLedger::create([
                'wallet_id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'txn_type' => 'withdrawal_approved',
                'direction' => 'debit',
                'amount' => $withdrawal->amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->available_balance,
                'reference_type' => 'withdrawal',
                'reference_id' => $withdrawal->id,
                'description' => 'Withdrawal approved by admin',
                'metadata' => [
                    'withdrawal_id' => $withdrawal->id,
                    'approved_by' => $admin->id,
                ],
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Withdrawal approved successfully',
                'data' => $withdrawal->fresh()->load('approver')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to approve withdrawal'], 500);
        }
    }

    /**
     * Reject a withdrawal request
     * POST /admin/v1/withdrawals/{id}/reject
     */
    public function reject(Request $request, string $id)
    {
        $withdrawal = Withdrawal::find($id);

        if (!$withdrawal) {
            return response()->json(['message' => 'Withdrawal not found'], 404);
        }

        if ($withdrawal->status !== 'pending') {
            return response()->json(['message' => 'Only pending withdrawals can be rejected'], 400);
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            // Update withdrawal status
            $withdrawal->status = 'rejected';
            $withdrawal->rejection_reason = $request->rejection_reason;
            $withdrawal->save();

            // Note: We don't touch the wallet balance here since the amount was never locked
            // The funds remain in the user's available balance

            // Create ledger entry for record
            $wallet = WalletAccount::find($withdrawal->wallet_id);
            if ($wallet) {
                WalletLedger::create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $wallet->user_id,
                    'txn_type' => 'withdrawal_rejected',
                    'direction' => 'none',
                    'amount' => $withdrawal->amount,
                    'balance_before' => $wallet->available_balance,
                    'balance_after' => $wallet->available_balance,
                    'reference_type' => 'withdrawal',
                    'reference_id' => $withdrawal->id,
                    'description' => 'Withdrawal rejected: ' . $request->rejection_reason,
                    'metadata' => [
                        'withdrawal_id' => $withdrawal->id,
                        'rejection_reason' => $request->rejection_reason,
                    ],
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Withdrawal rejected successfully',
                'data' => $withdrawal->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to reject withdrawal'], 500);
        }
    }

    /**
     * Mark withdrawal as paid
     * POST /admin/v1/withdrawals/{id}/paid
     */
    public function markAsPaid(Request $request, string $id)
    {
        $withdrawal = Withdrawal::find($id);

        if (!$withdrawal) {
            return response()->json(['message' => 'Withdrawal not found'], 404);
        }

        if ($withdrawal->status !== 'approved') {
            return response()->json(['message' => 'Only approved withdrawals can be marked as paid'], 400);
        }

        $validator = Validator::make($request->all(), [
            'gateway_name' => 'nullable|string',
            'gateway_ref_id' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $wallet = WalletAccount::find($withdrawal->wallet_id);
            
            if (!$wallet) {
                return response()->json(['message' => 'Wallet not found'], 404);
            }

            // Deduct from locked balance
            $balanceBefore = $wallet->locked_balance;
            $wallet->locked_balance -= $withdrawal->amount;
            $wallet->save();

            // Update withdrawal status
            $withdrawal->status = 'paid';
            $withdrawal->paid_at = now();
            if ($request->gateway_name) {
                $withdrawal->gateway_name = $request->gateway_name;
            }
            if ($request->gateway_ref_id) {
                $withdrawal->gateway_ref_id = $request->gateway_ref_id;
            }
            $withdrawal->metadata = array_merge($withdrawal->metadata ?? [], $request->metadata ?? []);
            $withdrawal->save();

            // Create ledger entry
            WalletLedger::create([
                'wallet_id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'txn_type' => 'withdrawal_paid',
                'direction' => 'none',
                'amount' => $withdrawal->amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->locked_balance,
                'reference_type' => 'withdrawal',
                'reference_id' => $withdrawal->id,
                'description' => 'Withdrawal paid to user',
                'metadata' => [
                    'withdrawal_id' => $withdrawal->id,
                    'gateway_name' => $withdrawal->gateway_name,
                    'gateway_ref_id' => $withdrawal->gateway_ref_id,
                ],
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Withdrawal marked as paid successfully',
                'data' => $withdrawal->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to mark withdrawal as paid'], 500);
        }
    }

    /**
     * Cancel a withdrawal (revert approval)
     * POST /admin/v1/withdrawals/{id}/cancel
     */
    public function cancel(Request $request, string $id)
    {
        $withdrawal = Withdrawal::find($id);

        if (!$withdrawal) {
            return response()->json(['message' => 'Withdrawal not found'], 404);
        }

        if (!in_array($withdrawal->status, ['pending', 'approved'])) {
            return response()->json(['message' => 'Only pending or approved withdrawals can be cancelled'], 400);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $wallet = WalletAccount::find($withdrawal->wallet_id);
            
            if ($withdrawal->status === 'approved' && $wallet) {
                // Refund the locked amount back to available balance
                $wallet->locked_balance -= $withdrawal->amount;
                $wallet->available_balance += $withdrawal->amount;
                $wallet->save();
            }

            // Update withdrawal status
            $withdrawal->status = 'cancelled';
            $withdrawal->rejection_reason = $request->reason;
            $withdrawal->save();

            // Create ledger entry
            if ($wallet) {
                WalletLedger::create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $wallet->user_id,
                    'txn_type' => 'withdrawal_cancelled',
                    'direction' => $withdrawal->status === 'approved' ? 'credit' : 'none',
                    'amount' => $withdrawal->amount,
                    'balance_before' => $wallet->available_balance - ($withdrawal->status === 'approved' ? $withdrawal->amount : 0),
                    'balance_after' => $wallet->available_balance,
                    'reference_type' => 'withdrawal',
                    'reference_id' => $withdrawal->id,
                    'description' => 'Withdrawal cancelled: ' . $request->reason,
                    'metadata' => [
                        'withdrawal_id' => $withdrawal->id,
                        'reason' => $request->reason,
                    ],
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Withdrawal cancelled successfully',
                'data' => $withdrawal->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to cancel withdrawal'], 500);
        }
    }

    /**
     * Get withdrawal statistics
     * GET /admin/v1/withdrawals/stats
     */
    public function stats(Request $request)
    {
        $query = Withdrawal::query();

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->get('from_date'));
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->get('to_date'));
        }

        $stats = [
            'total' => (clone $query)->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'approved' => (clone $query)->where('status', 'approved')->count(),
            'paid' => (clone $query)->where('status', 'paid')->count(),
            'rejected' => (clone $query)->where('status', 'rejected')->count(),
            'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
            'total_amount' => (clone $query)->sum('amount'),
            'pending_amount' => (clone $query)->where('status', 'pending')->sum('amount'),
            'approved_amount' => (clone $query)->where('status', 'approved')->sum('amount'),
        ];

        return response()->json([
            'data' => $stats
        ]);
    }
}
