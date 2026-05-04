<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    /**
     * Get user's referral code and stats
     * GET /user/v1/referrals
     */
    public function index(Request $request)
    {
        $user = $this->getUser();

        $referrals = $user->referrals()
            ->with('profile')
            ->orderByDesc('created_at')
            ->get();

        $totalReferrals = $referrals->count();
        $activeReferrals = $referrals->where('active', true)->count();

        return response()->json([
            'data' => [
                'referral_code' => $user->referral_code,
                'referral_link' => url('/signup?ref=' . $user->referral_code),
                'total_referrals' => $totalReferrals,
                'active_referrals' => $activeReferrals,
                'referrals_list' => $referrals,
            ]
        ]);
    }

    /**
     * Get referral earnings/rewards
     * GET /user/v1/referrals/earnings
     */
    public function earnings(Request $request)
    {
        $user = $this->getUser();

        $referralRewards = $user->rewards()
            ->whereHas('reward', function ($q) {
                $q->where('type', 'referral');
            })
            ->with('reward')
            ->orderByDesc('claimed_at')
            ->get();

        $totalEarnings = $referralRewards->sum(function ($reward) {
            return $reward->reward->value ?? 0;
        });

        return response()->json([
            'data' => [
                'total_earnings' => $totalEarnings,
                'rewards' => $referralRewards,
            ]
        ]);
    }
}
