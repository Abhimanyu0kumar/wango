<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Models\WalletAccount;
use App\Models\WalletLedger;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class WithdrawalController extends Controller
{
    /**
     * List authenticated user's withdrawals
     * GET /user/v1/withdrawals
     */
    public function index(Request $request)
    {
        $user = $this->getUser();

        $query = $user->withdrawals()->with('wallet');

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
     * Create a new withdrawal request
     * POST /user/v1/withdrawals
     */
    public function store(Request $request)
    {
        $user = $this->getUser();

        Log::info('Withdrawal request data:', $request->all());

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'payout_method' => 'required|string|in:upi,bank_transfer,crypto',
            // Temporarily loosened validation to find the cause
            'upi_id' => 'required_if:payout_method,upi',
            'account_name' => 'required_if:payout_method,bank_transfer',
            'account_number' => 'required_if:payout_method,bank_transfer',
            'ifsc_code' => 'required_if:payout_method,bank_transfer',
            'crypto_address' => 'required_if:payout_method,crypto',
        ]);

        if ($validator->fails()) {
            Log::info('Withdrawal validation failed:', $validator->errors()->toArray());
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Auto-select user's wallet if not provided
        $walletId = $request->wallet_id;
        if (!$walletId) {
            $wallet = $user->walletAccounts()->first();
            if (!$wallet) {
                return response()->json(['message' => 'No wallet found. Please create a wallet first.'], 404);
            }
            $walletId = $wallet->id;
        }

        $withdrawal = DB::transaction(function () use ($user, $walletId, $request) {
            // Lock wallet for update to prevent race conditions
            $wallet = WalletAccount::lockForUpdate()->find($walletId);
            
            if (!$wallet || $wallet->available_balance < $request->amount) {
                throw new \Exception('Insufficient balance');
            }

            $withdrawal = Withdrawal::create([
                'user_id' => $user->id,
                'wallet_id' => $walletId,
                'amount' => $request->amount,
                'payout_method' => $request->payout_method,
                'account_name' => $request->account_name,
                'account_number' => $request->account_number,
                'ifsc_code' => $request->ifsc_code,
                'upi_id' => $request->upi_id,
                'crypto_address' => $request->crypto_address,
                'status' => 'pending',
            ]);

            $balanceBefore = $wallet->available_balance;
            $wallet->available_balance -= $request->amount;
            $wallet->locked_balance += $request->amount;
            $wallet->save();

            WalletLedger::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'txn_type' => 'withdraw',
                'direction' => 'debit',
                'amount' => $request->amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->available_balance,
                'reference_type' => 'withdrawal',
                'reference_id' => $withdrawal->id,
                'description' => 'Withdrawal requested (Funds Locked)',
            ]);

            return $withdrawal;
        });

        return response()->json([
            'message' => 'Withdrawal request submitted and pending approval',
            'data' => $withdrawal
        ], 201);
    }

    /**
     * Show withdrawal detail
     * GET /user/v1/withdrawals/{id}
     */
    public function show(string $id)
    {
        $user = $this->getUser();

        $withdrawal = $user->withdrawals()->with('wallet')->find($id);

        if (!$withdrawal) {
            return response()->json(['message' => 'Withdrawal not found'], 404);
        }

        return response()->json([
            'data' => $withdrawal
        ]);
    }
}
