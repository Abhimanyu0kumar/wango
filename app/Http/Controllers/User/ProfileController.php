<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    /**
     * Get authenticated user's profile
     * GET /user/v1/me
     */
    public function show(Request $request)
    {
        $user = $this->getUser();

        return response()->json([
            'data' => [
                'user' => $user,
                'profile' => $user->profile,
                'wallet' => $user->walletAccounts()->first(),
                'kyc_status' => $user->profile?->kyc_status ?? 0,
            ]
        ]);
    }

    /**
     * Update authenticated user's profile
     * PUT /user/v1/me
     */
    public function update(Request $request)
    {
        $user = $this->getUser();

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'country_code' => 'nullable|string|size:2',
            'preferred_currency' => 'nullable|string|size:3',
            'address_data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Update or create profile
        $profileData = array_filter($validator->validated(), fn($v) => $v !== null);

        if ($user->profile) {
            $user->profile->update($profileData);
        } else {
            $user->profile()->create($profileData);
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'data' => $user->profile()->first()
        ]);
    }

    /**
     * Update avatar
     * PUT /user/v1/me/avatar
     */
    public function updateAvatar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'avatar_url' => 'required|url|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $this->getUser();

        if ($user->profile) {
            $user->profile->update(['avatar_url' => $request->avatar_url]);
        } else {
            $user->profile()->create(['avatar_url' => $request->avatar_url]);
        }

        return response()->json([
            'message' => 'Avatar updated successfully',
            'avatar_url' => $request->avatar_url
        ]);
    }
}
