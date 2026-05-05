<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = User::with('profile');

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $perPage = $request->get('limit', $request->get('per_page', 15));
        $users = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'users' => $users->items(),
                'pagination' => [
                    'page' => $users->currentPage(),
                    'limit' => $users->perPage(),
                    'total' => $users->total(),
                    'totalPages' => $users->lastPage(),
                ]
            ],
            'message' => 'Success'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|unique:users,phone|required_without:email',
            'email' => 'nullable|email|unique:users,email|required_without:phone',
            'password' => 'required|string|min:8',
            'referral_code' => 'nullable|string|unique:users,referral_code',
            'referred_by' => 'nullable|exists:users,id',
            'active' => 'boolean',
            'blocked' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        do {
            $referralCode = strtoupper(Str::random(8)); // Example: A8X9K2LM
        } while (User::where('referral_code', $referralCode)->exists());

        $userData = array_filter([
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'referral_code' => $data['referral_code'] ?? $referralCode,
            'referred_by' => $data['referred_by'] ?? null,
            'active' => $data['active'] ?? null,
            'blocked' => $data['blocked'] ?? null,
        ], fn($value) => $value !== null);

        $user = User::create($userData);

        // Create user profile
        $user->profile()->create([
            'name' => $data['name'],
        ]);

        // Create default wallet for user
        $user->walletAccounts()->create([
            'currency' => 'INR',
            'available_balance' => 0,
            'locked_balance' => 0,
            'status' => 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user->load('profile', 'walletAccounts')
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        return response()->json([
            'success' => true,
            'data' => $user->load('profile', 'walletAccounts')
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'nullable|string|unique:users,phone,' . $user->id,
            'email' => 'email|unique:users,email,' . $user->id,
            'password' => 'nullable|string|min:8',
            'referral_code' => 'nullable|string|unique:users,referral_code,' . $user->id,
            'referred_by' => 'nullable|exists:users,id',
            'active' => 'boolean',
            'blocked' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }

    public function UserActive(Request $request, User $user)
    {
        if (!$user->exists) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $active = $request->has('active') ? $request->boolean('active') : !$user->active;
        $user->update(['active' => $active]);

        return response()->json([
            'success' => true,
            'message' => 'User ' . ($active ? 'activated' : 'deactivated') . ' successfully',
            'data' => ['active' => $active]
        ]);
    }

    /**
     * Toggle or set user blocked status.
     */
    public function UserBlocked(Request $request, User $user)
    {
        if (!$user->exists) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $blocked = $request->has('blocked') ? $request->boolean('blocked') : !$user->blocked;
        $user->update(['blocked' => $blocked]);

        return response()->json([
            'success' => true,
            'message' => 'User ' . ($blocked ? 'blocked' : 'unblocked') . ' successfully',
            'data' => ['blocked' => $blocked]
        ]);
    }

    /**
     * Display user's profile.
     */
    public function showProfile(User $user)
    {
        if (!$user->exists) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $profile = $user->profile;

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $profile
        ]);
    }

    /**
     * Create or update user's profile.
     */
    public function updateProfile(Request $request, User $user)
    {
        if (!$user->exists) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'avatar_url' => 'nullable|string',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'country_code' => 'nullable|string|max:10',
            'preferred_currency' => 'nullable|string|max:10',
            'address_data' => 'nullable|array',
            'kyc_status' => 'nullable|integer|in:0,1,2,3',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        if ($user->profile) {
            $user->profile->update($data);
            $message = 'Profile updated successfully';
        } else {
            $data['user_id'] = $user->id;
            $profile = UserProfile::create($data);
            $message = 'Profile created successfully';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $user->profile->fresh()
        ]);
    }

    /**
     * Delete user's profile.
     */
    public function deleteProfile(User $user)
    {
        if (!$user->exists) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        if (!$user->profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found'
            ], 404);
        }

        $user->profile->delete();

        return response()->json([
            'success' => true,
            'message' => 'Profile deleted successfully'
        ]);
    }

    /**
     * Get user's wallets.
     */
    public function wallets(User $user)
    {
        if (!$user->exists) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $wallets = $user->walletAccounts()->with('ledgers')->get();

        return response()->json([
            'success' => true,
            'data' => $wallets
        ]);
    }

    /**
     * Get user's withdrawals.
     */
    public function withdrawals(Request $request, User $user)
    {
        if (!$user->exists) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        $query = $user->withdrawals()->with('wallet');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        $perPage = $request->get('per_page', 15);
        $withdrawals = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
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
     * Get user's wallet ledger entries.
     */
    public function ledgers(Request $request, User $user)
    {
        $query = \App\Models\WalletLedger::where('user_id', $user->id);

        if ($request->has('txn_type')) {
            $query->where('txn_type', $request->get('txn_type'));
        }

        $perPage = $request->get('per_page', 50);
        $ledgers = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $ledgers->items(),
            'meta' => [
                'current_page' => $ledgers->currentPage(),
                'last_page' => $ledgers->lastPage(),
                'per_page' => $ledgers->perPage(),
                'total' => $ledgers->total(),
            ]
        ]);
    }
}
