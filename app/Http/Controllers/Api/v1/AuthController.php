<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
    }
    #[OA\Post(
        path: '/api/v1/auth/login',
        summary: 'Đăng nhập',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                new OA\Property(property: 'email', type: 'string', example: 'admin@qlns.local'),
                new OA\Property(property: 'password', type: 'string', example: 'Admin@123'),
            ],
            ),
        ),
        responses: [
        new OA\Response(response: 200, description: 'Đăng nhập thành công, trả về token'),
        new OA\Response(response: 401, description: 'Sai email hoặc mật khẩu'),
        new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
    ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            $request,
        );

        return response()->json($this->formatTokenResponse($result));
    }
    #[OA\Post(
        path: '/api/v1/auth/refresh',
        summary: 'Làm mới access token',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refresh_token'],
                properties: [new OA\Property(property: 'refresh_token', type: 'string')],
            ),
        ),
        responses: [
        new OA\Response(response: 200, description: 'Cấp access_token/refresh_token mới'),
        new OA\Response(response: 401, description: 'Refresh token không hợp lệ hoặc đã hết hạn'),
    ],
    )]
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $result = $this->authService->refresh(
            $request->string('refresh_token')->toString(),
            $request,
        );

        return response()->json($this->formatTokenResponse($result));
    }
    #[OA\Post(
        path: '/api/v1/auth/logout',
        summary: 'Đăng xuất',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refresh_token'],
                properties: [new OA\Property(property: 'refresh_token', type: 'string')],
            ),
        ),
        responses: [
        new OA\Response(response: 200, description: 'Đăng xuất thành công'),
        new OA\Response(response: 401, description: 'Refresh token không hợp lệ'),
    ],
    )]
    public function logout(RefreshTokenRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authService->logout($user, $request->string('refresh_token')->toString());

        return response()->json(['message' => 'Đăng xuất thành công.']);
    }
    #[OA\Get(
        path: '/api/v1/auth/me',
        summary: 'Lấy thông tin user đang đăng nhập',
        tags: ['Auth'],
        security: [['bearerAuth' => []]],
        responses: [
        new OA\Response(response: 200, description: 'Thông tin user, roles, permissions'),
        new OA\Response(response: 401, description: 'Chưa đăng nhập hoặc token không hợp lệ'),
    ],
    )]
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
