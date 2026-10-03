<?php

namespace App\Services;

use App\Data\Auth\AuthenticationData;
use App\Data\Auth\LoginFormData;
use App\Data\Auth\RegisterFormData;
use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Authentication use cases (`docs/API_SPEC.md` §3).
 *
 * Owns account creation, credential verification and Sanctum token
 * issuance/revocation so `AuthController` stays a thin transport layer.
 */
class AuthService
{
    /**
     * Register a new account and issue a bearer token.
     *
     * @param  RegisterFormData  $formData
     * @return AuthenticationData
     */
    public function register(RegisterFormData $formData): AuthenticationData
    {
        $user = User::create([
            'name' => $formData->name,
            'email' => $formData->email,
            'password' => $formData->password,
        ]);

        return $this->issueAuthenticationData($user);
    }

    /**
     * Verify credentials and issue a bearer token.
     *
     * Unknown email and wrong password are indistinguishable: both raise
     * {@see InvalidCredentialsException} (`docs/SECURITY.md` §2).
     *
     * @throws InvalidCredentialsException
     */
    public function login(LoginFormData $formData): AuthenticationData
    {
        $user = User::query()->where('email', $formData->email)->first();

        if ($user === null || ! Hash::check($formData->password, $user->password)) {
            throw InvalidCredentialsException::create();
        }

        return $this->issueAuthenticationData($user);
    }

    /**
     * Revoke the personal access token used for the current request.
     *
     * Session-based (transient) tokens are not persisted, so there is nothing
     * to revoke for them.
     */
    public function logout(User $user): void
    {
        $accessToken = $user->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $accessToken->delete();
        }
    }

    /**
     * Issue a new personal access token for the given user.
     */
    private function issueAuthenticationData(User $user): AuthenticationData
    {
        return AuthenticationData::forUser(
            $user,
            $user->createToken('auth-token')->plainTextToken,
        );
    }
}
