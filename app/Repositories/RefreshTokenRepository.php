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

    public function revoke(RefreshToken $refreshToken): void
    {
        $refreshToken->forceFill(['revoked_at' => now()])->save();
    }
}
