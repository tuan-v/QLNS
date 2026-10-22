<?php

namespace App\Repositories;

use App\Models\RefreshToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RefreshTokenRepository
{
    public function create(User $user, string $tokenHash, Carbon $expiresAt, Request $request): RefreshToken
    {
        return RefreshToken::create([
            'user_id' => $user->id,
            'jwt_id' => (string) Str::uuid(),
            'token_hash' => $tokenHash,
            'device_name' => (string) $request->userAgent(),
            'ip_address' => (string) $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'expires_at' => $expiresAt,
        ]);
    }

    public function findValidByHash(string $tokenHash): ?RefreshToken
    {
        return RefreshToken::query()
            ->where('token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    // Khóa dòng theo hash (kể cả đã thu hồi) — AuthService::refresh() tự xét còn hiệu lực / còn ân hạn.
    public function findByHashForUpdate(string $tokenHash): ?RefreshToken
    {
        return RefreshToken::query()->where('token_hash', $tokenHash)->lockForUpdate()->first();
    }

    // Thu hồi vì BẢO MẬT (đăng xuất...) — tắt luôn ân hạn xoay vòng của token này.
    public function revoke(RefreshToken $refreshToken): void
    {
        $refreshToken->forceFill(['revoked_at' => now(), 'rotated_at' => null])->save();
    }

    // Đổi token cũ lấy token mới. Giữ rotated_at của LẦN XOAY ĐẦU để ân hạn không bị kéo dài mãi.
    public function rotate(RefreshToken $old, RefreshToken $new): void
    {
        $old->forceFill([
            'revoked_at' => $old->revoked_at ?? now(),
            'rotated_at' => $old->rotated_at ?? now(),
            'replaced_by_id' => $new->id,
        ])->save();
    }

    // Dùng khi đổi mật khẩu (qua quên mật khẩu) — chặn MỌI phiên đăng nhập cũ
    // tự làm mới access token nữa. Access token JWT đang có (tối đa còn hạn
    // 1h) vẫn còn hiệu lực tới khi tự hết hạn (không thể thu hồi ngay vì JWT
    // không lưu trạng thái ở server), nhưng sau đó không refresh được tiếp.
    public function revokeAllForUser(User $user): void
    {
        RefreshToken::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
        // Token vừa xoay vòng cũng mất ân hạn — không để dùng lại sau khi đổi mật khẩu / khóa tài khoản.
        RefreshToken::query()
            ->where('user_id', $user->id)
            ->whereNotNull('rotated_at')
            ->update(['rotated_at' => null]);
    }
}
