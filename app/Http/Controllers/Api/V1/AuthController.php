<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
// "OA" chỉ là BÍ DANH (alias) do `use ... as OA` tự đặt cho thư viện swagger-php
// (namespace OpenApi\Attributes) — để bên dưới viết ngắn #[OA\Post(...)] thay vì
// #[OpenApi\Attributes\Post(...)]. Chữ OA viết tắt của OpenAPI, chuẩn mô tả REST
// API mà Swagger dùng. Cách đọc các khối #[OA\...] xem chú thích ở login().
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    // Các khối #[OA\...] là "Attribute" của PHP 8 (giống annotation @Operation/
    // @ApiResponse của Swagger bên Java). Chúng CHỈ dùng để sinh TÀI LIỆU API
    // (trang Swagger UI tại /api/documentation, theo yêu cầu dự án), KHÔNG ảnh
    // hưởng gì tới việc chạy — xóa hết thì API vẫn chạy y nguyên. Đọc từng phần:
    //   OA\Post(path, summary, tags)      endpoint dùng POST, đường dẫn, tên hiển
    //                                     thị, nhóm ("Auth") trong giao diện Swagger
    //   OA\RequestBody + OA\JsonContent   mô tả JSON client phải gửi lên
    //   OA\Property(property, type,       từng trường trong JSON; `required` liệt kê
    //               example)              trường bắt buộc, `example` là giá trị mẫu
    //                                     điền sẵn ở nút "Try it out"
    //   OA\Response(response, description) các mã HTTP có thể trả về và ý nghĩa
    // Tài liệu được sinh từ các khối này bằng lệnh `php artisan l5-swagger:generate`
    // (ra file storage/api-docs/api-docs.json). Cấu hình đang KHÔNG tự sinh lại
    // (generate_always=false) nên sửa khối này xong phải chạy lại lệnh đó, nếu
    // không trang /api/documentation vẫn hiện bản cũ.
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
    // security: [['bearerAuth' => []]] = endpoint này CẦN đăng nhập — Swagger UI sẽ
    // gửi kèm header "Authorization: Bearer <access_token>". Tên 'bearerAuth' phải
    // khớp #[OA\SecurityScheme] khai báo ở app/Http/Controllers/Controller.php.
    // login/refresh không có dòng này vì lúc gọi chưa có token.
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
