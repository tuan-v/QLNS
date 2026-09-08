<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $passwordResetService)
    {
    }

    #[OA\Post(
        path: '/api/v1/auth/forgot-password',
        summary: 'Yêu cầu đặt lại mật khẩu qua email',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [new OA\Property(property: 'email', type: 'string', example: 'admin@qlns.local')],
            ),
        ),
        responses: [
        new OA\Response(response: 200, description: 'Luôn trả về thành công — không tiết lộ email có tồn tại hay không'),
        new OA\Response(response: 422, description: 'Email sai định dạng'),
    ],
    )]
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->requestReset($request->string('email')->toString(), $request);

        return response()->json([
            'message' => 'Nếu email tồn tại trong hệ thống, liên kết đặt lại mật khẩu đã được gửi.',
        ]);
    }

    #[OA\Post(
        path: '/api/v1/auth/reset-password',
        summary: 'Đặt mật khẩu mới bằng token nhận qua email',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['token', 'password', 'password_confirmation'],
                properties: [
                new OA\Property(property: 'token', type: 'string'),
                new OA\Property(property: 'password', type: 'string'),
                new OA\Property(property: 'password_confirmation', type: 'string'),
            ],
            ),
        ),
        responses: [
        new OA\Response(response: 200, description: 'Đặt lại mật khẩu thành công'),
        new OA\Response(response: 422, description: 'Token không hợp lệ/hết hạn hoặc dữ liệu sai định dạng'),
    ],
    )]
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->passwordResetService->reset(
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        return response()->json([
            'message' => 'Đặt lại mật khẩu thành công. Vui lòng đăng nhập lại.',
        ]);
    }
}
