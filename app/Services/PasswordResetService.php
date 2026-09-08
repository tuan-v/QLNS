<?php

namespace App\Services;

use App\Mail\PasswordResetMail;
use App\Models\PasswordHistory;
use App\Repositories\PasswordResetRepository;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PasswordResetRepository $passwordResetRepository,
        private readonly RefreshTokenRepository $refreshTokenRepository,
    ) {
    }

    // Luôn trả về thành công phía Controller kể cả khi email không tồn tại —
    // nếu báo "email không tồn tại" sẽ lộ ra ai đang có tài khoản trong hệ
    // thống (User Enumeration, nằm trong OWASP Top-10 mà tài liệu yêu cầu
    // dự án nhắc tới ở mục Bảo mật).
    public function requestReset(string $email, Request $request): void
    {
        $user = $this->userRepository->findActiveByEmail($email);

        if (! $user) {
            return;
        }

        $this->passwordResetRepository->invalidateAllForUser($user);

        $plainToken = Str::random(64);

        $this->passwordResetRepository->create(
            $user,
            hash('sha256', $plainToken),
            now()->addMinutes(60),
            $request->ip(),
        );

        $resetUrl = rtrim((string) config('app.url'), '/')
            . '/reset-password?token=' . $plainToken
            . '&email=' . urlencode($user->email);

        // Gửi đồng bộ vì chưa có worker hàng chờ chạy nền trong docker-compose
        // hiện tại (QUEUE_CONNECTION=redis đã cấu hình nhưng không có service
        // nào chạy `queue:work`) — nếu queue mail ở đây mà không có worker,
        // email sẽ nằm mãi trong Redis, không bao giờ thật sự gửi.
        Mail::to($user->email)->send(new PasswordResetMail($user, $resetUrl));
    }

    public function reset(string $token, string $newPassword): void
    {
        $record = $this->passwordResetRepository->findValidByHash(hash('sha256', $token));

        if (! $record) {
            throw ValidationException::withMessages([
                'token' => 'Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.',
            ]);
        }

        DB::transaction(function () use ($record, $newPassword) {
            $user = $record->user;

            PasswordHistory::create([
                'user_id' => $user->id,
                'password_hash' => $user->password,
            ]);

            $user->forceFill([
                'password' => Hash::make($newPassword),
                'password_changed_at' => now(),
            ])->save();

            $this->passwordResetRepository->markUsed($record);
            $this->refreshTokenRepository->revokeAllForUser($user);
        });
    }
}
