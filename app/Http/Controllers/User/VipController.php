<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\UserReward;
use App\Models\VipTier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VipController extends Controller
{
    /**
     * Get user's VIP status and tiers
     * GET /user/v1/vip/status
     */
    public function status(Request $request)
    {
        $user = $this->getUser();
        $vipStatus = $user->vipStatus()->with('vipTier')->first();

        $allTiers = VipTier::where('status', true)
            ->orderBy('min_lifetime_points')
            ->get();

        return response()->json([
            'data' => [
                'current_status' => $vipStatus,
                'all_tiers' => $allTiers,
                'next_tier' => $this->getNextTier($allTiers, $vipStatus?->points ?? 0),
            ]
        ]);
    }

    /**
     * Get available rewards
     * GET /user/v1/vip/rewards
     */
    public function rewards(Request $request)
    {
        $user = $this->getUser();
        $userPoints = $user->vipStatus?->points ?? 0;

        $availableRewards = \App\Models\Reward::where('is_active', true)
            ->where('points_required', '<=', $userPoints)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where(function ($q) {
                $q->whereNull('quantity')
                    ->orWhere('quantity', '>', 0);
            })
            ->get();

        $claimedRewards = $user->rewards()
            ->with('reward')
            ->orderByDesc('claimed_at')
            ->get();

        return response()->json([
            'data' => [
                'available' => $availableRewards,
                'claimed' => $claimedRewards,
            ]
        ]);
    }

    /**
     * Claim a reward
     * POST /user/v1/vip/rewards/claim
     */
    public function claimReward(Request $request)
    {
        $user = $this->getUser();

        $validator = Validator::make($request->all(), [
            'reward_id' => 'required|exists:rewards,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $reward = \App\Models\Reward::find($request->reward_id);
        $userPoints = $user->vipStatus?->points ?? 0;

        if ($reward->points_required > $userPoints) {
            return response()->json(['message' => 'Insufficient points'], 400);
        }

        if ($reward->quantity !== null && $reward->quantity <= 0) {
            return response()->json(['message' => 'Reward out of stock'], 400);
        }

        if ($reward->expires_at && $reward->expires_at < now()) {
            return response()->json(['message' => 'Reward expired'], 400);
        }

        $existingClaim = UserReward::where('user_id', $user->id)
            ->where('reward_id', $reward->id)
            ->where('status', '!=', 'used')
            ->first();

        if ($existingClaim) {
            return response()->json(['message' => 'Already claimed this reward'], 400);
        }

        $userReward = UserReward::create([
            'user_id' => $user->id,
            'reward_id' => $reward->id,
            'status' => 'claimed',
            'claimed_at' => now(),
            'expires_at' => $reward->expires_at,
            'code' => strtoupper(uniqid('REW-')),
        ]);

        if ($reward->quantity !== null) {
            $reward->decrement('quantity');
        }

        return response()->json([
            'message' => 'Reward claimed successfully',
            'data' => $userReward->load('reward')
        ], 201);
    }

    /**
     * Get VIP benefits history
     * GET /user/v1/vip/benefits
     */
    public function benefits(Request $request)
    {
        $user = $this->getUser();
        $vipStatus = $user->vipStatus()->with('vipTier')->first();

        if (!$vipStatus || !$vipStatus->vipTier) {
            return response()->json([
                'data' => [
                    'current_benefits' => [],
                    'cashback_rate' => 0,
                ]
            ]);
        }

        $tier = $vipStatus->vipTier;

        return response()->json([
            'data' => [
                'tier_name' => $tier->name,
                'tier_level' => $tier->level,
                'points' => $vipStatus->points,
                'cashback_rate' => $tier->cashback_rate,
                'benefits' => $tier->benefits ?? [],
            ]
        ]);
    }

    private function getNextTier($tiers, $currentPoints)
    {
        foreach ($tiers as $tier) {
            if ($tier->min_lifetime_points > $currentPoints) {
                return $tier;
            }
        }
        return null;
    }
}
