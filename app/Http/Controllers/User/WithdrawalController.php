<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WithdrawalController extends Controller
{
    /**
     * List authenticated user's withdrawals
     * GET /user/v1/withdrawals
     */
    public function index(Request $request)
    {
        $user = auth('api')->user();

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
        $user = auth('api')->user();

        $validator = Validator::make($request->all(), [
            'wallet_id' => 'required|exists:wallet_accounts,id,user_id,' . $user->id,
            'amount' => 'required|numeric|min:1',
            'payout_method' => 'required|string|in:upi,bank_transfer,crypto',
            'account_name' => 'required_if:payout_method,bank_transfer|string',
            'account_number' => 'required_if:payout_method,bank_transfer|string',
            'ifsc_code' => 'required_if:payout_method,bank_transfer|string',
            'upi_id' => 'required_if:payout_method,upi|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check wallet balance
        $wallet = $user->walletAccounts()->find($request->wallet_id);
        if (!$wallet || $wallet->available_balance < $request->amount) {
            return response()->json(['message' => 'Insufficient balance'], 400);
        }

        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'wallet_id' => $request->wallet_id,
            'amount' => $request->amount,
            'payout_method' => $request->payout_method,
            'account_name' => $request->account_name,
            'account_number' => $request->account_number,
            'ifsc_code' => $request->ifsc_code,
            'upi_id' => $request->upi_id,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Withdrawal request submitted successfully',
            'data' => $withdrawal
        ], 201);
    }

    /**
     * Show withdrawal detail
     * GET /user/v1/withdrawals/{id}
     */
    public function show(string $id)
    {
        $user = auth('api')->user();

        $withdrawal = $user->withdrawals()->with('wallet')->find($id);

        if (!$withdrawal) {
            return response()->json(['message' => 'Withdrawal not found'], 404);
        }

        return response()->json([
            'data' => $withdrawal
        ]);
    }
}
