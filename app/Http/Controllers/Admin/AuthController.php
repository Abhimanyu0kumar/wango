<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Http\Resources\LoginResource;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    protected static string $admin_token = 'wangoadminlogin';

    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        $admin = Admin::where('email', $data['email'])->first();

        if (!$admin || !Hash::check($data['password'], $admin->password)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        $admin->tokens()->delete();
        $token = $admin->createToken(self::$admin_token)->accessToken;

        return new LoginResource($admin, $token);
    }

    public function logout(Request $request)
    {
        $admin = auth('admin')->user();

        // Revoke the current access token
        $bearerToken = $request->bearerToken();
        if ($bearerToken) {
            try {
                $parts = explode('.', $bearerToken);
                if (count($parts) === 3) {
                    $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
                    $tokenId = $payload['jti'] ?? null;

                    if ($tokenId) {
                        $tokenRecord = \Laravel\Passport\Token::find($tokenId);
                        if ($tokenRecord) {
                            $tokenRecord->revoke();
                        }
                    }
                }
            } catch (\Exception $e) {
                // Token parsing failed, continue with logout
            }
        }

        return response()->json([
            'message' => 'Admin logged out successfully'
        ], 200);
    }
}
