<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Http\Resources\LoginResource;
use App\Models\Admin;
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

        return (new LoginResource($admin, $token))
            ->additional([
                'message' => 'Admin logged in successfully.'
            ]);
    }

    public function logout()
    {
        $admin = request()->admin();
        $admin->token()->revoke();

        return response()->json([
            'message' => 'Admin logged out successfully'
        ], 200);
    }
}
