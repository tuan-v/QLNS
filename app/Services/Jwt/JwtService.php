<?php

namespace App\Services\Jwt;

use App\Models\User;
use DomainException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Str;
use InvalidArgumentException;
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

    // Bắt thêm DomainException/InvalidArgumentException (Ngày 44 — phát hiện
    // qua kiểm thử thật): firebase/php-jwt không chỉ ném 3 loại lỗi ban đầu —
    // token bị hỏng dạng khác (vd payload giải mã base64 ra không phải JSON
    // hợp lệ) ném DomainException ("Unexpected control character found"),
    // không nằm trong danh sách bắt cũ nên vỡ thành 500 Internal Server Error
    // thay vì trả về 401 sạch để Frontend tự đăng xuất/làm mới token.
    public function decodeAccessToken(string $token): ?stdClass
    {
        try {
            return JWT::decode($token, new Key($this->secret, $this->algo));
        } catch (ExpiredException|SignatureInvalidException|UnexpectedValueException|DomainException|InvalidArgumentException) {
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
