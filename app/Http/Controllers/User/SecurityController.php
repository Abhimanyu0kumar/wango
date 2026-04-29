<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class SecurityController extends Controller
{
    /**
     * Get security settings
     * GET /user/v1/security
     */
    public function show(Request $request)
    {
        $user = auth('api')->user();
        $security = $user->securitySettings;

        return response()->json([
            'data' => [
                'two_factor_enabled' => $security?->two_factor_enabled ?? false,
                'pin_code_set' => !empty($security?->pin_code),
                'last_password_change' => $security?->last_password_change,
                'failed_login_attempts' => $security?->failed_login_attempts ?? 0,
                'locked_until' => $security?->locked_until,
            ]
        ]);
    }

    /**
     * Update password
     * PUT /user/v1/security/password
     */
    public function updatePassword(Request $request)
    {
        $user = auth('api')->user();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 401);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        // Update security log
        if ($user->securitySettings) {
            $user->securitySettings->update(['last_password_change' => now()]);
        } else {
            $user->securitySettings()->create(['last_password_change' => now()]);
        }

        return response()->json([
            'message' => 'Password updated successfully'
        ]);
    }

    /**
     * Set/Update PIN code
     * PUT /user/v1/security/pin
     */
    public function updatePin(Request $request)
    {
        $user = auth('api')->user();

        $validator = Validator::make($request->all(), [
            'pin' => 'required|string|size:4',
            'current_pin' => 'nullable|string|size:4',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // If updating existing PIN, verify current PIN
        if ($user->securitySettings?->pin_code) {
            if (!Hash::check($request->current_pin, $user->securitySettings->pin_code)) {
                return response()->json(['message' => 'Current PIN is incorrect'], 401);
            }
        }

        if ($user->securitySettings) {
            $user->securitySettings->update(['pin_code' => Hash::make($request->pin)]);
        } else {
            $user->securitySettings()->create(['pin_code' => Hash::make($request->pin)]);
        }

        return response()->json([
            'message' => 'PIN updated successfully'
        ]);
    }

    /**
     * Toggle 2FA
     * PUT /user/v1/security/2fa
     */
    public function toggle2FA(Request $request)
    {
        $user = auth('api')->user();

        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verify password
        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Password is incorrect'], 401);
        }

        if ($user->securitySettings) {
            $user->securitySettings->update(['two_factor_enabled' => $request->enabled]);
        } else {
            $user->securitySettings()->create(['two_factor_enabled' => $request->enabled]);
        }

        return response()->json([
            'message' => 'Two-factor authentication ' . ($request->enabled ? 'enabled' : 'disabled') . ' successfully'
        ]);
    }

    /**
     * List active devices
     * GET /user/v1/security/devices
     */
    public function devices(Request $request)
    {
        $user = auth('api')->user();
        $devices = $user->devices()->where('is_active', true)->get();

        return response()->json([
            'data' => $devices
        ]);
    }

    /**
     * Revoke a device
     * DELETE /user/v1/security/devices/{id}
     */
    public function revokeDevice(string $id)
    {
        $user = auth('api')->user();

        $device = $user->devices()->find($id);

        if (!$device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $device->update(['is_active' => false]);

        return response()->json([
            'message' => 'Device revoked successfully'
        ]);
    }
}
