<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class AdminController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Admin::with(['profile', 'roles']);

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhereHas('profile', function ($pq) use ($search) {
                      $pq->where('name', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%")
                         ->orWhere('department', 'like', "%{$search}%")
                         ->orWhere('designation', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->get('role'));
            });
        }

        if ($request->has('department')) {
            $query->whereHas('profile', function ($q) use ($request) {
                $q->where('department', $request->get('department'));
            });
        }

        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $admins = $query->paginate($perPage);

        return response()->json([
            'data' => $admins->items(),
            'meta' => [
                'current_page' => $admins->currentPage(),
                'last_page' => $admins->lastPage(),
                'per_page' => $admins->perPage(),
                'total' => $admins->total(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:admins,email',
            'password' => 'required|string|min:8',
            'status' => 'nullable|boolean',
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name',
            // Profile fields
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:20',
            'avatar_url' => 'nullable|string',
            'department' => 'nullable|string|max:100',
            'designation' => 'nullable|string|max:100',
            'timezone' => 'nullable|string|max:50',
            'locale' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Create admin
        $adminData = [
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => $data['status'] ?? true,
        ];

        $admin = Admin::create($adminData);

        // Create profile
        $profileData = [
            'admin_id' => $admin->id,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'avatar_url' => $data['avatar_url'] ?? null,
            'department' => $data['department'] ?? null,
            'designation' => $data['designation'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'locale' => $data['locale'] ?? null,
        ];

        AdminProfile::create($profileData);

        // Assign roles
        if (!empty($data['roles'])) {
            $admin->syncRoles($data['roles']);
        }

        $admin->load(['profile', 'roles']);

        return response()->json([
            'message' => 'Admin created successfully',
            'data' => $admin
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Admin $admin)
    {
        return response()->json(['data' => $admin->load(['profile', 'roles'])]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Admin $admin)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'email|unique:admins,email,' . $admin->id,
            'password' => 'nullable|string|min:8',
            'status' => 'nullable|boolean',
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name',
            // Profile fields
            'name' => 'string|max:120',
            'phone' => 'nullable|string|max:20',
            'avatar_url' => 'nullable|string',
            'department' => 'nullable|string|max:100',
            'designation' => 'nullable|string|max:100',
            'timezone' => 'nullable|string|max:50',
            'locale' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Update admin
        $adminData = [];
        if (isset($data['email'])) {
            $adminData['email'] = $data['email'];
        }
        if (isset($data['password'])) {
            $adminData['password'] = Hash::make($data['password']);
        }
        if (isset($data['status'])) {
            $adminData['status'] = $data['status'];
        }

        if (!empty($adminData)) {
            $admin->update($adminData);
        }

        // Update or create profile
        $profileFields = ['name', 'phone', 'avatar_url', 'department', 'designation', 'timezone', 'locale'];
        $profileData = [];
        foreach ($profileFields as $field) {
            if (array_key_exists($field, $data)) {
                $profileData[$field] = $data[$field];
            }
        }

        if (!empty($profileData)) {
            if ($admin->profile) {
                $admin->profile->update($profileData);
            } else {
                $profileData['admin_id'] = $admin->id;
                AdminProfile::create($profileData);
            }
        }

        // Update roles
        if (isset($data['roles'])) {
            $admin->syncRoles($data['roles']);
        }

        $admin->load(['profile', 'roles']);

        return response()->json([
            'message' => 'Admin updated successfully',
            'data' => $admin
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Admin $admin)
    {
        $admin->delete();

        return response()->json(['message' => 'Admin deleted successfully']);
    }

    /**
     * Toggle admin status.
     */
    public function toggleStatus(Admin $admin)
    {
        $admin->status = !$admin->status;
        $admin->save();

        return response()->json([
            'message' => 'Admin ' . ($admin->status ? 'activated' : 'deactivated') . ' successfully',
            'status' => $admin->status
        ]);
    }

    /**
     * Get current admin profile.
     */
    public function me(Request $request)
    {
        $admin = $request->user('admin')->load(['profile', 'roles']);
        return response()->json(['data' => $admin]);
    }

    /**
     * Update current admin profile.
     */
    public function updateMe(Request $request)
    {
        $admin = $request->user('admin');

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:120',
            'phone' => 'nullable|string|max:20',
            'avatar_url' => 'nullable|string',
            'department' => 'nullable|string|max:100',
            'designation' => 'nullable|string|max:100',
            'timezone' => 'nullable|string|max:50',
            'locale' => 'nullable|string|max:20',
            'current_password' => 'nullable|string|required_with:password',
            'password' => 'nullable|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Update password if provided
        if (isset($data['password'])) {
            if (!Hash::check($data['current_password'], $admin->password)) {
                return response()->json(['errors' => ['current_password' => ['Current password is incorrect']]], 422);
            }
            $admin->password = Hash::make($data['password']);
            $admin->save();
        }

        // Update profile
        $profileFields = ['name', 'phone', 'avatar_url', 'department', 'designation', 'timezone', 'locale'];
        $profileData = [];
        foreach ($profileFields as $field) {
            if (array_key_exists($field, $data)) {
                $profileData[$field] = $data[$field];
            }
        }

        if (!empty($profileData)) {
            if ($admin->profile) {
                $admin->profile->update($profileData);
            } else {
                $profileData['admin_id'] = $admin->id;
                AdminProfile::create($profileData);
            }
        }

        $admin->load(['profile', 'roles']);

        return response()->json([
            'message' => 'Profile updated successfully',
            'data' => $admin
        ]);
    }
}
