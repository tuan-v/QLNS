<?php

namespace App\Repositories;

use App\Models\PasswordResetToken;
use App\Models\User;
use Carbon\Carbon;

class PasswordResetRepository
{
    public function create(User $user, string $tokenHash, Carbon $expiresAt, ?string $ip): PasswordResetToken
    {
        return PasswordResetToken::create([
            'user_id' => $user->id,
            'token_hash' => $tokenHash,
            'requested_ip' => $ip,
            'expires_at' => $expiresAt,
        ]);
    }

    public function findValidByHash(string $tokenHash): ?PasswordResetToken
    {
        return PasswordResetToken::query()
            ->where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    public function markUsed(PasswordResetToken $token): void
    {
        $token->forceFill(['used_at' => now()])->save();
    }

    // Yêu cầu đặt lại mật khẩu mới thì mọi liên kết CŨ chưa dùng của user này
    // phải hết hiệu lực ngay — tránh 1 email cũ bị lộ (đọc trộm hộp thư) vẫn
    // còn dùng được sau khi người dùng đã tự yêu cầu link mới hơn.
    public function invalidateAllForUser(User $user): void
    {
        PasswordResetToken::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }
}
