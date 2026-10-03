<?php

namespace App\Http\Controllers\Api;

use App\Data\Auth\AuthenticationData;
use App\Data\User\UserDetailData;
use App\Exceptions\InvalidCredentialsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Auth endpoints (`docs/API_SPEC.md` §3).
 */
class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $attributes = $request->validated();

        $user = User::create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'password' => $attributes['password'],
        ]);

        return response()->json([
            'data' => AuthenticationData::forUser($user, $this->issueToken($user)),
            'message' => 'Registrasi berhasil.',
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $attributes = $request->validated();

        $user = User::query()->where('email', $attributes['email'])->first();

        if ($user === null || ! Hash::check($attributes['password'], $user->password)) {
            throw InvalidCredentialsException::create();
        }

        return response()->json([
            'data' => AuthenticationData::forUser($user, $this->issueToken($user)),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $accessToken = $request->user()->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }

        return response()->json([
            'data' => null,
            'message' => 'Logout berhasil.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => UserDetailData::from($request->user()),
        ]);
    }

    /**
     * Issue a new personal access token for the given user.
     */
    private function issueToken(User $user): string
    {
        return $user->createToken('auth-token')->plainTextToken;
    }
}
