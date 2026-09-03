<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login(
                $request->string('email')->toString(),
                $request->string('password')->toString(),
                $request,
            );
        } catch (AuthenticationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return response()->json($this->formatTokenResponse($result));
    }

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->refresh(
                $request->string('refresh_token')->toString(),
                $request,
            );
        } catch (AuthenticationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return response()->json($this->formatTokenResponse($result));
    }

    public function logout(RefreshTokenRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $this->authService->logout($user, $request->string('refresh_token')->toString());
        } catch (AuthenticationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return response()->json(['message' => 'Đăng xuất thành công.']);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->load('roles');

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'user_name' => $user->user_name,
            'status' => $user->status,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->permissionCodes(),
        ]);
    }

    private function formatTokenResponse(array $result): array
    {
        return [
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => $result['expires_in'],
            'user' => [
                'id' => $result['user']->id,
                'email' => $result['user']->email,
                'user_name' => $result['user']->user_name,
            ],
        ];
    }
}
