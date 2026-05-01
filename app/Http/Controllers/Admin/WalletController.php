<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WalletController extends Controller
{
    /**
     * List all wallets with optional filters
     * GET /admin/v1/wallets
     */
    public function index(Request $request)
    {
        $query = WalletAccount::with(['user', 'ledgers']);

        // Filter by user
        if ($request->has('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        // Filter by currency
        if ($request->has('currency')) {
            $query->where('currency', $request->get('currency'));
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        // Search by user email/phone
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $perPage = $request->get('per_page', 15);
        $wallets = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $wallets->items(),
            'meta' => [
                'current_page' => $wallets->currentPage(),
                'last_page' => $wallets->lastPage(),
                'per_page' => $wallets->perPage(),
                'total' => $wallets->total(),
            ]
        ]);
    }

    /**
     * Show specific wallet details
     * GET /admin/v1/wallets/{id}
     */
    public function show(string $id)
    {
        $wallet = WalletAccount::with(['user', 'ledgers' => function ($query) {
            $query->orderByDesc('created_at')->limit(50);
        }])->find($id);

        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found'], 404);
        }

        return response()->json([
            'data' => $wallet
        ]);
    }

    /**
     * Get wallet ledger entries
     * GET /admin/v1/wallets/{id}/ledgers
     */
    public function ledgers(Request $request, string $id)
    {
        $wallet = WalletAccount::find($id);

        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found'], 404);
        }

        $query = $wallet->ledgers();

        // Filter by transaction type
        if ($request->has('txn_type')) {
            $query->where('txn_type', $request->get('txn_type'));
        }

        // Filter by direction
        if ($request->has('direction')) {
            $query->where('direction', $request->get('direction'));
        }

        // Date range filter
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->get('from_date'));
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->get('to_date'));
        }

        $perPage = $request->get('per_page', 50);
        $ledgers = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $ledgers->items(),
            'meta' => [
                'current_page' => $ledgers->currentPage(),
                'last_page' => $ledgers->lastPage(),
                'per_page' => $ledgers->perPage(),
                'total' => $ledgers->total(),
            ]
        ]);
    }

    /**
     * Credit user wallet (admin action)
     * POST /admin/v1/wallets/credit (create wallet if not exists)
     * POST /admin/v1/wallets/{id}/credit (credit existing wallet)
     * 
     * If wallet doesn't exist, can create one by passing user_id and currency
     */
    public function credit(Request $request, $id = null)
    {
        // If ID is provided and is numeric, try to find existing wallet
        if ($id && is_numeric($id)) {
            $wallet = WalletAccount::find($id);
        } else {
            $wallet = null;
        }

        // If wallet doesn't exist, try to create it
        if (!$wallet) {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'currency' => 'required|string|max:10',
                'amount' => 'required|numeric|min:0.01',
                'description' => 'required|string',
                'reference_type' => 'nullable|string',
                'reference_id' => 'nullable|integer',
                'metadata' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            // Check if wallet already exists for this user and currency
            $existingWallet = WalletAccount::where('user_id', $request->user_id)
                ->where('currency', $request->currency)
                ->first();

            if ($existingWallet) {
                // Wallet exists, credit it instead
                $wallet = $existingWallet;
            } else {
                // Create new wallet
                $wallet = WalletAccount::create([
                    'user_id' => $request->user_id,
                    'currency' => $request->currency,
                    'available_balance' => 0,
                    'locked_balance' => 0,
                    'status' => 'active',
                ]);
            }
        } else {
            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:0.01',
                'description' => 'required|string',
                'reference_type' => 'nullable|string',
                'reference_id' => 'nullable|integer',
                'metadata' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
        }

        DB::beginTransaction();
        try {
            $balanceBefore = $wallet->available_balance;
            $wallet->available_balance += $request->amount;
            $wallet->save();

            // Create ledger entry
            WalletLedger::create([
                'wallet_id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'txn_type' => 'admin_credit',
                'direction' => 'credit',
                'amount' => $request->amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->available_balance,
                'reference_type' => $request->reference_type,
                'reference_id' => $request->reference_id,
                'description' => $request->description,
                'metadata' => $request->metadata ?? [],
            ]);

            DB::commit();

            $message = $wallet->wasRecentlyCreated
                ? 'Wallet created and credited successfully'
                : 'Wallet credited successfully';

            return response()->json([
                'message' => $message,
                'data' => $wallet->fresh()->load('user')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to credit wallet'], 500);
        }
    }

    /**
     * Debit user wallet (admin action)
     * POST /admin/v1/wallets/{id}/debit
     */
    public function debit(Request $request, string $id)
    {
        $wallet = WalletAccount::find($id);

        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($wallet->available_balance < $request->amount) {
            return response()->json(['message' => 'Insufficient balance'], 400);
        }

        DB::beginTransaction();
        try {
            $balanceBefore = $wallet->available_balance;
            $wallet->available_balance -= $request->amount;
            $wallet->save();

            // Create ledger entry
            WalletLedger::create([
                'wallet_id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'txn_type' => 'admin_debit',
                'direction' => 'debit',
                'amount' => $request->amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->available_balance,
                'reference_type' => $request->reference_type,
                'reference_id' => $request->reference_id,
                'description' => $request->description,
                'metadata' => $request->metadata ?? [],
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Wallet debited successfully',
                'data' => $wallet->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to debit wallet'], 500);
        }
    }

    /**
     * Lock/Unlock funds in wallet
     * POST /admin/v1/wallets/{id}/lock
     * POST /admin/v1/wallets/{id}/unlock
     */
    public function lock(Request $request, string $id)
    {
        $wallet = WalletAccount::find($id);

        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($wallet->available_balance < $request->amount) {
            return response()->json(['message' => 'Insufficient available balance'], 400);
        }

        DB::beginTransaction();
        try {
            $balanceBefore = $wallet->available_balance;
            $wallet->available_balance -= $request->amount;
            $wallet->locked_balance += $request->amount;
            $wallet->save();

            // Create ledger entry
            WalletLedger::create([
                'wallet_id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'txn_type' => 'lock',
                'direction' => 'debit',
                'amount' => $request->amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->available_balance,
                'description' => $request->description,
                'metadata' => ['locked' => true],
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Funds locked successfully',
                'data' => $wallet->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to lock funds'], 500);
        }
    }

    public function unlock(Request $request, string $id)
    {
        $wallet = WalletAccount::find($id);

        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($wallet->locked_balance < $request->amount) {
            return response()->json(['message' => 'Insufficient locked balance'], 400);
        }

        DB::beginTransaction();
        try {
            $balanceBefore = $wallet->available_balance;
            $wallet->locked_balance -= $request->amount;
            $wallet->available_balance += $request->amount;
            $wallet->save();

            // Create ledger entry
            WalletLedger::create([
                'wallet_id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'txn_type' => 'unlock',
                'direction' => 'credit',
                'amount' => $request->amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $wallet->available_balance,
                'description' => $request->description,
                'metadata' => ['unlocked' => true],
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Funds unlocked successfully',
                'data' => $wallet->fresh()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to unlock funds'], 500);
        }
    }

    /**
     * Update wallet status
     * PATCH /admin/v1/wallets/{id}/status
     */
    public function updateStatus(Request $request, string $id)
    {
        $wallet = WalletAccount::find($id);

        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:active,frozen,closed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $wallet->status = $request->status;
        $wallet->save();

        return response()->json([
            'message' => 'Wallet status updated successfully',
            'data' => $wallet
        ]);
    }

    /**
     * Create a new wallet for a user
     * POST /admin/v1/wallets
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'currency' => 'required|string|max:10',
            'available_balance' => 'required|numeric|min:0',
            'status' => 'nullable|string|in:active,frozen,closed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check if wallet already exists for this user and currency
        $existingWallet = WalletAccount::where('user_id', $request->user_id)
            ->where('currency', $request->currency)
            ->first();

        if ($existingWallet) {
            // Update existing wallet
            $existingWallet->available_balance = $request->available_balance;
            if ($request->has('status')) {
                $existingWallet->status = $request->status;
            }
            $existingWallet->save();

            return response()->json([
                'success' => true,
                'message' => 'Wallet updated successfully',
                'data' => $existingWallet->load('user')
            ]);
        }

        // Create new wallet
        $wallet = WalletAccount::create([
            'user_id' => $request->user_id,
            'currency' => $request->currency,
            'available_balance' => $request->available_balance,
            'locked_balance' => 0,
            'status' => $request->status ?? 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Wallet created successfully',
            'data' => $wallet->load('user')
        ], 201);
    }

    /**
     * Freeze wallet (convenience method)
     * POST /admin/v1/wallets/{id}/freeze
     */
    public function freeze(string $id)
    {
        $wallet = WalletAccount::find($id);

        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found'], 404);
        }

        $wallet->status = 'frozen';
        $wallet->save();

        return response()->json([
            'message' => 'Wallet frozen successfully',
            'data' => $wallet
        ]);
    }

    /**
     * Unfreeze wallet (convenience method)
     * POST /admin/v1/wallets/{id}/unfreeze
     */
    public function unfreeze(string $id)
    {
        $wallet = WalletAccount::find($id);

        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found'], 404);
        }

        $wallet->status = 'active';
        $wallet->save();

        return response()->json([
            'message' => 'Wallet unfrozen successfully',
            'data' => $wallet
        ]);
    }
}
