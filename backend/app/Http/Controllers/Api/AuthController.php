<?php

namespace App\Http\Controllers\Api;

use App\Data\Auth\LoginFormData;
use App\Data\Auth\RegisterFormData;
use App\Data\User\UserDetailData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Responses\ApiResponse;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Auth endpoints (`docs/API_SPEC.md` §3).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $formData = RegisterFormData::from($request);

        return ApiResponse::created(
            data: $this->authService->register($formData),
            message: 'Registrasi berhasil.',
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $formData = LoginFormData::from($request);

        return ApiResponse::single($this->authService->login($formData));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return ApiResponse::single(null, 'Logout berhasil.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::single(UserDetailData::from($request->user()));
    }
}
