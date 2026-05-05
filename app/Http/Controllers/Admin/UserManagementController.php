<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserDevice;
use App\Models\UserKycDocument;
use App\Models\WalletAccount;
use App\Models\WalletLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UserManagementController extends Controller
{
    /**
     * List all users with filters
     */
    public function index(Request $request)
    {
        $query = User::with('profile', 'walletAccounts');

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('referral_code', 'like', "%{$search}%");
            });
        }

        if ($request->has('active')) {
            $query->where('active', $request->get('active'));
        }

        if ($request->has('blocked')) {
            $query->where('blocked', $request->get('blocked'));
        }

        if ($request->has('kyc_status')) {
            $query->whereHas('profile', function ($q) use ($request) {
                $q->where('kyc_status', $request->get('kyc_status'));
            });
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $users = $query->paginate($perPage);

        return response()->json([
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ]
        ]);
    }

    /**
     * Show user details
     */
    public function show(string $id)
    {
        $user = User::with('profile', 'walletAccounts', 'devices', 'kycDocuments')->find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        return response()->json(['data' => $user]);
    }

    /**
     * Update user wallet balance
     */
    public function updateWallet(Request $request, string $userId)
    {
        $validator = Validator::make($request->all(), [
            'wallet_id' => 'nullable|exists:wallet_accounts,id',
            'amount' => 'required|numeric',
            'type' => 'required|in:credit,debit',
            'reason' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $data = $validator->validated();
        $walletId = $data['wallet_id'] ?? $user->walletAccounts()->first()?->id;

        if (!$walletId) {
            return response()->json(['message' => 'No wallet found for user'], 404);
        }

        $wallet = WalletAccount::where('id', $walletId)->where('user_id', $userId)->first();
        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found for this user'], 404);
        }

        try {
            return DB::transaction(function () use ($wallet, $user, $data) {
                $amount = abs($data['amount']);
                $isCredit = $data['type'] === 'credit';

                if ($isCredit) {
                    $wallet->available_balance += $amount;
                } else {
                    if ($wallet->available_balance < $amount) {
                        return response()->json(['message' => 'Insufficient balance'], 422);
                    }
                    $wallet->available_balance -= $amount;
                }

                $wallet->save();

                WalletLedger::create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $user->id,
                    'txn_type' => 'admin_adjustment',
                    'direction' => $isCredit ? 'credit' : 'debit',
                    'amount' => $amount,
                    'balance_before' => $isCredit ? $wallet->available_balance - $amount : $wallet->available_balance + $amount,
                    'balance_after' => $wallet->available_balance,
                    'reference_type' => 'admin',
                    'reference_id' => auth('admin')->id(),
                    'description' => $data['reason'],
                    'metadata' => [
                        'adjustment_type' => $data['type'],
                        'admin_id' => auth('admin')->id(),
                    ],
                ]);

                return response()->json([
                    'message' => 'Wallet updated successfully',
                    'data' => [
                        'wallet_id' => $wallet->id,
                        'available_balance' => $wallet->available_balance,
                        'locked_balance' => $wallet->locked_balance,
                    ]
                ]);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to update wallet: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Reset/update user password
     */
    public function updatePassword(Request $request, string $userId)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user->password = Hash::make($validator->validated()['password']);
        $user->save();

        // Revoke all tokens to force re-login
        $user->tokens()->delete();

        return response()->json(['message' => 'Password updated successfully']);
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request, string $userId)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:64',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other,1,2,3',
            'country_code' => 'nullable|string|size:2',
            'preferred_currency' => 'nullable|string|size:3',
            'address_data' => 'nullable|array',
            'avatar_url' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $profile = $user->profile;
        if (!$profile) {
            $profile = $user->profile()->create([]);
        }

        $profileData = $validator->validated();

        // Convert gender string to integer for database
        if (isset($profileData['gender']) && is_string($profileData['gender'])) {
            $genderMap = ['male' => 1, 'female' => 2, 'other' => 3];
            $profileData['gender'] = $genderMap[$profileData['gender']] ?? null;
        }

        $profile->update($profileData);

        return response()->json([
            'message' => 'Profile updated successfully',
            'data' => $profile->fresh()
        ]);
    }

    /**
     * Get user device details
     */
    public function getDevices(string $userId)
    {
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $devices = UserDevice::where('user_id', $userId)
            ->orderBy('last_seen_at', 'desc')
            ->get();

        return response()->json(['data' => $devices]);
    }

    /**
     * Verify/reject KYC document
     */
    public function verifyKyc(Request $request, string $kycId)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:approved,rejected',
            'rejection_reason' => 'nullable|string|max:500',
            'expires_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $kyc = UserKycDocument::find($kycId);
        if (!$kyc) {
            return response()->json(['message' => 'KYC document not found'], 404);
        }

        if ($kyc->status !== 'pending') {
            return response()->json(['message' => 'KYC document already processed'], 422);
        }

        $data = $validator->validated();

        try {
            return DB::transaction(function () use ($kyc, $data) {
                $kyc->status = $data['status'];
                $kyc->rejection_reason = $data['rejection_reason'] ?? null;
                $kyc->expires_at = $data['expires_at'] ?? null;
                $kyc->reviewed_at = now();
                $kyc->reviewed_by = auth('admin')->id();
                $kyc->save();

                // Update user profile KYC status
                $profile = $kyc->user->profile;
                if ($profile) {
                    $profile->kyc_status = $data['status'] === 'approved' ? 2 : 3;
                    $profile->save();
                }

                return response()->json([
                    'message' => 'KYC document ' . $data['status'] . ' successfully',
                    'data' => $kyc->fresh()
                ]);
            });
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to process KYC: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Upload/change user profile picture
     */
    public function updateProfilePicture(Request $request, string $userId)
    {
        $validator = Validator::make($request->all(), [
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

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
                'message' => 'Profile picture updated successfully',
                'data' => ['avatar_url' => $avatarUrl]
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to upload image: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Block/unblock user
     */
    public function toggleBlock(Request $request, string $userId)
    {
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user->blocked = !$user->blocked;
        $user->save();

        return response()->json([
            'message' => $user->blocked ? 'User blocked successfully' : 'User unblocked successfully',
            'data' => ['blocked' => $user->blocked]
        ]);
    }

    /**
     * Activate/deactivate user
     */
    public function toggleActive(Request $request, string $userId)
    {
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user->active = !$user->active;
        $user->save();

        return response()->json([
            'message' => $user->active ? 'User activated successfully' : 'User deactivated successfully',
            'data' => ['active' => $user->active]
        ]);
    }
}
