<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\LoginRequest;
use App\Http\Requests\User\SignupRequest;
use App\Http\Resources\UserLoginResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    protected static string $user_token = 'wangouserlogin';

    /**
     * Generate a unique referral code
     */
    private function generateReferralCode(): string
    {
        do {
            $code = strtoupper(substr(md5(uniqid()), 0, 8));
        } while (User::where('referral_code', $code)->exists());

        return $code;
    }

    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        $user = User::where('email', $data['email_or_phone'])->orWhere('phone', $data['email_or_phone'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        $user->tokens()->delete();
        $token = $user->createToken(self::$user_token)->accessToken;

        return (new UserLoginResource($user, $token))
            ->additional([
                'message' => 'User logged in successfully.'
            ]);
    }

    public function signup(SignupRequest $request)
    {
        $data = $request->validated();

        $query = User::query();

        if (! empty($data['email'])) {
            $query->orWhere('email', $data['email']);
        }

        if (! empty($data['phone'])) {
            $query->orWhere('phone', $data['phone']);
        }

        $existing = $query->first();

        if ($existing) {
            $errors = [];

            if (! empty($data['email']) && $existing->email === $data['email']) {
                $errors['email'] = ['This email is already registered.'];
            }

            if (! empty($data['phone']) && $existing->phone === $data['phone']) {
                $errors['phone'] = ['This phone number is already registered.'];
            }

            return response()->json([
                'message' => 'Account already exists',
                'errors' => $errors,
            ], 422);
        }

        $user = User::create([
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'referral_code' => $this->generateReferralCode(),
        ]);

        // Create user profile
        $user->profile()->create([
            'name' => $data['name'],
        ]);

        $token = $user->createToken(self::$user_token)->accessToken;

        return (new UserLoginResource($user->load('profile'), $token))
            ->additional([
                'message' => 'User registered successfully.',
                'status' => 'success'
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function logout(Request $request)
    {
        $user = auth('api')->user();

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
            'message' => 'User logged out successfully'
        ], 200);
    }
}
