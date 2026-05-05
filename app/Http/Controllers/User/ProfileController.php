<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

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

        // Convert gender string to integer for database
        if (isset($profileData['gender'])) {
            $genderMap = ['male' => 1, 'female' => 2, 'other' => 3];
            $profileData['gender'] = $genderMap[$profileData['gender']] ?? null;
        }

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
     * Update avatar with file upload
     * POST /user/v1/me/avatar
     */
    public function updateAvatar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $this->getUser();

        try {
            $profile = $user->profile;
            if (!$profile) {
                $profile = $user->profile()->create([]);
            }

            // Delete old avatar if exists
            if ($profile->avatar_url) {
                Storage::disk('public')->delete(str_replace('/storage/', '', $profile->avatar_url));
            }

            // Upload new avatar
            $path = $request->file('avatar')->store('avatars', 'public');
            $avatarUrl = Storage::url($path);

            $profile->avatar_url = $avatarUrl;
            $profile->save();

            return response()->json([
                'message' => 'Avatar updated successfully',
                'data' => ['avatar_url' => $avatarUrl]
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to upload image: ' . $e->getMessage()], 500);
        }
    }
}
