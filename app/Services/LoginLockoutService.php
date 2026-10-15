<?php

namespace App\Services;

use App\Mail\AccountLockedMail;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

// Tự khóa TÀI KHOẢN tạm thời khi bị dò mật khẩu (2026-10-14, OWASP A07). Giới hạn ở
// AppServiceProvider đếm theo cặp email+IP — botnet đổi IP liên tục vẫn dò được 1 tài
// khoản. Ở đây đếm theo EMAIL, cộng dồn mọi IP. Lưu trong cache (tạm thời, tự hết hạn).
//
// Đánh đổi: kẻ xấu cố ý nhập sai để khóa tài khoản người khác -> chỉ khóa có thời hạn,
// chủ tài khoản được email báo và đặt lại mật khẩu (Quên mật khẩu) là mở khóa ngay.
class LoginLockoutService
{
    public function __construct(private readonly LogService $logService)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('rate_limits.enabled');
    }

    // Gọi TRƯỚC khi kiểm tra mật khẩu: đang khóa thì từ chối luôn, kể cả nhập ĐÚNG mật
    // khẩu — nếu không, kẻ dò vẫn biết được lần nào trúng.
    public function ensureNotLocked(string $email): void
    {
        $until = $this->enabled() ? Cache::get($this->lockKey($email)) : null;
        if (! $until || $until <= now()->timestamp) {
            return;
        }

        $seconds = $until - now()->timestamp;
        $minutes = (int) ceil($seconds / 60);

        throw new HttpResponseException(response()->json([
            'message' => "Tài khoản tạm khóa do nhập sai mật khẩu nhiều lần. Vui lòng thử lại sau {$minutes} phút hoặc dùng \"Quên mật khẩu\" để đặt lại.",
        ], 429, ['Retry-After' => $seconds]));
    }

    // Đếm 1 lần sai. Đủ ngưỡng -> khóa + email báo chủ tài khoản (1 lần mỗi đợt khóa).
    // Email không tồn tại vẫn đếm/khóa như thường — để kẻ dò không phân biệt được
    // email nào có thật qua phản hồi; chỉ là không có ai để gửi thư.
    public function recordFailure(string $email, ?User $user, ?string $ip): void
    {
        if (! $this->enabled()) {
            return;
        }

        $failures = RateLimiter::hit($this->failKey($email), config('rate_limits.account_lock_window_minutes') * 60);
        if ($failures < config('rate_limits.account_lock_attempts')) {
            return;
        }

        $minutes = (int) config('rate_limits.account_lock_minutes');
        Cache::put($this->lockKey($email), now()->addMinutes($minutes)->timestamp, $minutes * 60);
        RateLimiter::clear($this->failKey($email));
        $this->logService->recordAuthEvent('login_locked', $user, $email, ['minutes' => $minutes]);

        if ($user) {
            Mail::to($user->email)->queue(new AccountLockedMail($user, $minutes, $ip));
        }
    }

    // Đăng nhập đúng / đặt lại mật khẩu xong -> xóa số lần sai và mở khóa.
    public function clear(string $email): void
    {
        RateLimiter::clear($this->failKey($email));
        Cache::forget($this->lockKey($email));
    }

    private function failKey(string $email): string
    {
        return 'login-fail:'.Str::lower(trim($email));
    }

    private function lockKey(string $email): string
    {
        return 'login-locked:'.Str::lower(trim($email));
    }
}
