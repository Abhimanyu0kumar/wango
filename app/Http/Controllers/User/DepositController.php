<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Deposit;
use App\Models\WalletAccount;
use App\Models\WalletLedger;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DepositController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->getUser();

        $query = $user->deposits()->with('wallet');

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
        $deposits = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $deposits->items(),
            'meta' => [
                'current_page' => $deposits->currentPage(),
                'last_page' => $deposits->lastPage(),
                'per_page' => $deposits->perPage(),
                'total' => $deposits->total(),
            ]
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->getUser();

        $validator = Validator::make($request->all(), [
            'wallet_id' => 'nullable|exists:wallet_accounts,id,user_id,' . $user->id,
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|string|in:upi,bank_transfer,card,crypto',
            'gateway_name' => 'nullable|string',
        ]);

        if ($validator->fails()) {
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

        $deposit = DB::transaction(function () use ($user, $walletId, $request) {
            $deposit = Deposit::create([
                'user_id' => $user->id,
                'wallet_id' => $walletId,
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'gateway_name' => $request->gateway_name ?? 'manual',
                'merchant_order_id' => 'DEP-' . strtoupper(uniqid()),
                'status' => 'success',
                'paid_at' => now(),
            ]);

            $wallet = WalletAccount::lockForUpdate()->find($walletId);
            $balanceBefore = $wallet->available_balance;
            $wallet->available_balance += $request->amount;
            $wallet->save();

            WalletLedger::create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'txn_type' => 'deposit',
                'direction' => 'credit',
                'amount' => $request->amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->available_balance,
                'reference_type' => 'deposit',
                'reference_id' => $deposit->id,
                'description' => 'Deposit via ' . strtoupper($request->payment_method),
            ]);

            Transaction::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'transaction_code' => $deposit->merchant_order_id,
                'source_table' => 'deposits',
                'source_id' => $deposit->id,
                'amount' => $request->amount,
                'txn_type' => 'credit',
                'status' => 'success',
            ]);

            return $deposit;
        });

        return response()->json([
            'message' => 'Deposit successful and balance updated',
            'data' => $deposit
        ], 201);
    }

    /**
     * Show deposit detail
     * GET /user/v1/deposits/{id}
     */
    public function show(string $id)
    {
        $user = $this->getUser();

        $deposit = $user->deposits()->with('wallet')->find($id);

        if (!$deposit) {
            return response()->json(['message' => 'Deposit not found'], 404);
        }

        return response()->json([
            'data' => $deposit
        ]);
    }
}
