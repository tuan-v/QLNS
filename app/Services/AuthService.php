<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\RefreshTokenRepository;
use App\Repositories\UserRepository;
use App\Services\Jwt\JwtService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly JwtService $jwtService,
    ) {
    }

    public function login(string $email, string $password, Request $request): array
    {
        $user = $this->userRepository->findActiveByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new AuthenticationException('Email hoặc mật khẩu không đúng.');
        }

        return DB::transaction(function () use ($user, $request) {
            $this->userRepository->touchLastLogin($user);

            return $this->issueTokenPair($user, $request);
        });
    }

    public function refresh(string $refreshToken, Request $request): array
    {
        $tokenHash = $this->jwtService->hashRefreshToken($refreshToken);
        $record = $this->refreshTokenRepository->findValidByHash($tokenHash);

        if (! $record) {
            throw new AuthenticationException('Refresh token không hợp lệ hoặc đã hết hạn.');
        }

        return DB::transaction(function () use ($record, $request) {
            $this->refreshTokenRepository->revoke($record);

            return $this->issueTokenPair($record->user, $request);
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
    }

    private function issueTokenPair(User $user, Request $request): array
    {
        $accessToken = $this->jwtService->issueAccessToken($user);
        $refreshTokenPlain = $this->jwtService->generateRefreshTokenPlain();

        $this->refreshTokenRepository->create(
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
        ];
    }
}
