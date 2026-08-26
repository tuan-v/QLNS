<?php

namespace App\Services\Jwt;

use App\Models\User;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Str;
use stdClass;
use UnexpectedValueException;

class JwtService
{
    private string $secret;

    private string $algo;

    private int $accessTtlMinutes;

    public function __construct()
    {
        $this->secret = (string) config('config_jwt.secret');
        $this->algo = (string) config('config_jwt.algo');
        $this->accessTtlMinutes = (int) config('config_jwt.access_ttl_minutes');
    }

    public function issueAccessToken(User $user): string
    {
        $now = time();

        $payload = [
            'iss' => config('app.url'),
            'sub' => $user->id,
            'iat' => $now,
            'exp' => $now + ($this->accessTtlMinutes * 60),
            'permissions' => $user->permissionCodes(),
        ];

        return JWT::encode($payload, $this->secret, $this->algo);
    }

    public function accessTtlSeconds(): int
    {
        return $this->accessTtlMinutes * 60;
    }

    public function decodeAccessToken(string $token): ?stdClass
    {
        try {
            return JWT::decode($token, new Key($this->secret, $this->algo));
        } catch (ExpiredException|SignatureInvalidException|UnexpectedValueException) {
            return null;
        }
    }

    public function refreshTtlDays(): int
    {
        return (int) config('config_jwt.refresh_ttl_days');
    }

    public function generateRefreshTokenPlain(): string
    {
        return Str::random(80);
    }

    public function hashRefreshToken(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
