<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * Get authenticated user's wallet
     * GET /user/v1/wallet
     */
    public function show(Request $request)
    {
        $user = $this->getUser();
        $wallet = $user->walletAccounts()->first();
        if (!$wallet) {
            // Create a default wallet if none exists
            $wallet = $user->walletAccounts()->create([
                'currency' => 'INR',
                'available_balance' => 0,
                'locked_balance' => 0,
                'status' => 'active',
            ]);
        }

        return response()->json([
            'data' => $wallet
        ]);
    }

    /**
     * Get wallet ledger entries
     * GET /user/v1/wallet/ledgers
     */
    public function ledgers(Request $request)
    {
        $user = $this->getUser();
        $walletId = $request->get('wallet_id');

        $query = $user->walletAccounts()->with('ledgers');

        if ($walletId) {
            $query->where('id', $walletId);
        }

        $wallets = $query->get();
        $ledgers = $wallets->pluck('ledgers')->flatten();

        return response()->json([
            'data' => $ledgers
        ]);
    }
}
