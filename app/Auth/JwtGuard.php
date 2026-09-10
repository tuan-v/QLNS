<?php

namespace App\Auth;

use App\Services\Jwt\JwtService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;

class JwtGuard implements Guard
{
    private ?Authenticatable $user = null;

    // Cache theo TOKEN đã giải mã, không phải cờ true/false đơn thuần — nếu
    // chỉ cache true/false, guard này (được AuthManager giữ lại dùng chung
    // suốt vòng đời container) sẽ trả nhầm user của lần request TRƯỚC cho
    // mọi lần gọi sau, dù request hiện tại mang Bearer token khác hẳn. Lỗi
    // này không lộ ở request thật ngoài đời (mỗi request PHP-FPM là 1 tiến
    // trình mới, container tự hủy sau khi trả response) nhưng lộ rõ trong
    // PHPUnit feature test: nhiều lệnh gọi HTTP trong CÙNG 1 test method dùng
    // chung 1 `$app` container, nên guard bị tái sử dụng — test đăng nhập lần
    // lượt 2 tài khoản khác nhau rồi gọi API sẽ luôn thấy user của lần đăng
    // nhập ĐẦU, không phải lần gọi hiện tại. Phát hiện lúc viết test cho luồng
    // "nhân viên tự xem hồ sơ mình" (đăng nhập admin tạo dữ liệu, rồi đăng
    // nhập tài khoản nhân viên để kiểm tra quyền hạn) — 2 test đầu tiên trong
    // cả dự án thật sự cần đổi user giữa 2 lệnh gọi HTTP.
    private ?string $resolvedToken = null;

    public function __construct(
        private readonly UserProvider $provider,
        private readonly JwtService $jwtService,
    ) {
    }

    public function check(): bool
    {
        return ! is_null($this->user());
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        // request() lấy đúng Request đang gắn trong container TẠI THỜI ĐIỂM
        // gọi — không tiêm qua constructor (sẽ bị đóng băng theo Request lúc
        // guard được tạo, sai ngay khi guard bị dùng lại cho request khác).
        $token = $this->bearerToken();

        if ($this->resolvedToken !== null && $token === $this->resolvedToken) {
            return $this->user;
        }

        $this->resolvedToken = $token ?? '';

        if (! $token) {
            return $this->user = null;
        }

        $payload = $this->jwtService->decodeAccessToken($token);

        if (! $payload || ! isset($payload->sub)) {
            return $this->user = null;
        }

        $user = $this->provider->retrieveById($payload->sub);

        if (! $user || $user->status !== 'active') {
            return $this->user = null;
        }

        return $this->user = $user;

    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return ! is_null($this->user);
    }

    public function setUser(Authenticatable $user): void
    {
        $this->user = $user;
        // Đánh dấu "đã resolve" theo đúng cơ chế cache mới (theo token, xem
        // property $resolvedToken) — gán thẳng $this->resolved (tên cũ, đã bỏ
        // khi sửa cache) chỉ tạo ra 1 property động vô dụng, không chặn được
        // user() gọi tiếp tự giải mã lại token và ghi đè mất user vừa set tay
        // (vd Laravel test helper actingAs() gọi setUser() rồi gọi tiếp API).
        $this->resolvedToken = $this->bearerToken() ?? '';
    }

    private function bearerToken(): ?string
    {
        $header = request()->header('Authorization', '');

        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return substr($header, 7);
    }
}
