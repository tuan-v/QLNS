<?php

namespace App\Services;

use App\Models\PasswordHistory;
use App\Models\User;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;
use App\Services\Jwt\JwtService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Refresh token đã xoay vòng vẫn được dùng lại trong chừng này giây. Lý do: trang đang gửi yêu cầu làm
     * mới thì người dùng F5 / đóng tab — server đã cấp token mới nhưng trình duyệt chưa kịp lưu, trang mới
     * gửi lại token cũ và bị đẩy ra đăng nhập (lỗi thật 2026-10-21). Thu hồi vì bảo mật thì không có ân hạn.
     */
    public const ROTATION_GRACE_SECONDS = 30;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly JwtService $jwtService,
        private readonly LoginLockoutService $loginLockout,
        private readonly LogService $logService,
    ) {
    }

    public function login(string $email, string $password, Request $request): array
    {
        // 1) Đang bị khóa tạm -> từ chối luôn, chưa xét mật khẩu (xem LoginLockoutService).
        $this->loginLockout->ensureNotLocked($email);

        $user = $this->userRepository->findActiveByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            // 2) Sai -> đếm 1 lần (cộng dồn mọi IP), đủ ngưỡng thì khóa + email báo.
            // Ghi "sai mật khẩu" TRƯỚC — lần sai thứ N gây khóa thì nhật ký đọc đúng thứ tự.
            $this->logService->recordAuthEvent('login_failed', $user, $email);
            $this->loginLockout->recordFailure($email, $user, $request->ip());

            throw new AuthenticationException('Email hoặc mật khẩu không đúng.');
        }

        // 3) Đúng -> xóa số lần sai trước đó.
        $this->loginLockout->clear($email);
        $this->logService->recordAuthEvent('login', $user);

        return DB::transaction(function () use ($user, $request) {
            $this->userRepository->touchLastLogin($user);

            $pair = $this->issueTokenPair($user, $request);
            unset($pair['refresh_record']);

            return $pair;
        });
    }

    public function refresh(string $refreshToken, Request $request): array
    {
        $tokenHash = $this->jwtService->hashRefreshToken($refreshToken);

        return DB::transaction(function () use ($tokenHash, $request) {
            $record = $this->refreshTokenRepository->findByHashForUpdate($tokenHash);

            $usable = $record && $record->expires_at->isFuture() && (
                $record->revoked_at === null
                || ($record->rotated_at !== null && $record->rotated_at->gte(now()->subSeconds(self::ROTATION_GRACE_SECONDS)))
            );
            if (! $usable || $record->user?->status !== 'active') {
                throw new AuthenticationException('Refresh token không hợp lệ hoặc đã hết hạn.');
            }

            // Dùng lại trong ân hạn: token mới cấp lần trước chưa ai nhận được -> thu hồi nó, cấp cặp khác.
            if ($record->replaced_by_id) {
                $previous = $record->replacedBy;
                if ($previous && $previous->revoked_at === null) {
                    $this->refreshTokenRepository->revoke($previous);
                }
            }

            $pair = $this->issueTokenPair($record->user, $request);
            $this->refreshTokenRepository->rotate($record, $pair['refresh_record']);
            unset($pair['refresh_record']);

            return $pair;
        });
    }

    public function logout(User $user, string $refreshToken): void
    {
        $tokenHash = $this->jwtService->hashRefreshToken($refreshToken);
        $record = $this->refreshTokenRepository->findValidByHash($tokenHash);

        if (! $record || $record->user_id !== $user->id) {
            throw new AuthenticationException('Refresh token không hợp lệ.');
        }

        $this->refreshTokenRepository->revoke($record);
        $this->logService->recordAuthEvent('logout', $user);
    }

    // Đổi mật khẩu khi đang đăng nhập: sai mật khẩu hiện tại -> 422. Đổi xong thu hồi MỌI phiên (máy khác phải đăng
    // nhập lại bằng mật khẩu mới) rồi cấp cặp token mới cho chính phiên này để người dùng không bị đẩy ra.
    public function changePassword(User $user, string $currentPassword, string $newPassword, Request $request): array
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Mật khẩu hiện tại không đúng.']);
        }

        $pair = DB::transaction(function () use ($user, $newPassword, $request) {
            PasswordHistory::create(['user_id' => $user->id, 'password_hash' => $user->password]);
            $user->forceFill(['password' => Hash::make($newPassword), 'password_changed_at' => now()])->save();
            $this->refreshTokenRepository->revokeAllForUser($user);

            $pair = $this->issueTokenPair($user, $request);
            unset($pair['refresh_record']);

            return $pair;
        });

        $this->logService->recordAuthEvent('password_changed', $user);

        return $pair;
    }

    private function issueTokenPair(User $user, Request $request): array
    {
        $accessToken = $this->jwtService->issueAccessToken($user);
        $refreshTokenPlain = $this->jwtService->generateRefreshTokenPlain();

        $refreshRecord = $this->refreshTokenRepository->create(
            $user,
            $this->jwtService->hashRefreshToken($refreshTokenPlain),
            now()->addDays($this->jwtService->refreshTtlDays()),
            $request,
        );

        return [
            'user' => $user,
            'access_token' => $accessToken,
            'refresh_token' => $refreshTokenPlain,
            'expires_in' => $this->jwtService->accessTtlSeconds(),
            // Chỉ dùng nội bộ (refresh() nối token cũ -> mới); login() bỏ đi trước khi trả về.
            'refresh_record' => $refreshRecord,
        ];
    }
}
