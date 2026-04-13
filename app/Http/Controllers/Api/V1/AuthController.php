<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AuthService $authService) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());

        return $this->success([
            'user' => $user,
            'token' => $this->authService->issueToken($user, 'register-token'),
        ], 'Registered successfully.', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->only('email', 'password'))) {
            return $this->failure('Invalid credentials.', 422);
        }

        /** @var User $user */
        $user = Auth::user();
        $token = $this->authService->issueToken($user, $request->validated('device_name', 'login-token'));

        return $this->success(['user' => $user, 'token' => $token], 'Login successful.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success($request->user(), 'Current user fetched.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return $this->success(null, 'Logged out.');
    }
}
