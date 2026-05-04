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
        $wallets = $user->walletAccounts()->with('ledgers')->get();

        return response()->json([
            'data' => $wallets
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
