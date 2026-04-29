<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
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
            'name' => 'nullable|string|max:255',           // Add this
            'phone' => 'nullable|string|unique:users,phone',
            'email' => 'required|email|unique:users,email', // Keep email required
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
        $data['password'] = Hash::make($data['password']);

        do {
            $referralCode = strtoupper(Str::random(8)); // Example: A8X9K2LM
        } while (User::where('referral_code', $referralCode)->exists());

        $data['referral_code'] = $referralCode;

        // Remove null values to keep DB clean
        $data = array_filter($data, fn($value) => $value !== null);

        $user = User::create($data);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully',
            'data' => $user
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        return response()->json([
            'success' => true,
            'data' => $user->load('profile')
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

    /**
     * Toggle or set user active status.
     */
    public function UserActive(Request $request, User $user)
    {
        // Support both explicit value and toggle behavior
        if ($request->has('active')) {
            $user->active = $request->boolean('active');
        } else {
            $user->active = !$user->active;
        }
        
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User ' . ($user->active ? 'activated' : 'deactivated') . ' successfully',
            'data' => ['active' => $user->active]
        ]);
    }

    /**
     * Toggle or set user blocked status.
     */
    public function UserBlocked(Request $request, User $user)
    {
        // Support both explicit value and toggle behavior
        if ($request->has('blocked')) {
            $user->blocked = $request->boolean('blocked');
        } else {
            $user->blocked = !$user->blocked;
        }
        
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User ' . ($user->blocked ? 'blocked' : 'unblocked') . ' successfully',
            'data' => ['blocked' => $user->blocked]
        ]);
    }
}