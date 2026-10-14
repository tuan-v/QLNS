<?php

namespace App\Providers;

use App\Auth\JwtGuard;
use App\Services\Jwt\JwtService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::extend('jwt', function ($app, string $name, array $config) {
            return new JwtGuard(
                Auth::createUserProvider($config['provider']),
                $app->make(JwtService::class),
            );
        });

        $this->registerRateLimiters();
    }

    // Giới hạn số lần gọi API — số liệu ở config/rate_limits.php. Vượt giới hạn trả
    // 429 kèm thông báo tiếng Việt và header Retry-After.
    private function registerRateLimiters(): void
    {
        $tooManyAttempts = function (Request $request, array $headers) {
            $seconds = (int) ($headers['Retry-After'] ?? 60);

            return response()->json([
                'message' => "Bạn thao tác quá nhiều lần. Vui lòng thử lại sau {$seconds} giây.",
            ], 429, $headers);
        };

        RateLimiter::for('login', function (Request $request) use ($tooManyAttempts) {
            if (! config('rate_limits.enabled')) {
                return Limit::none();
            }

            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(config('rate_limits.login_per_email'))->by($email.'|'.$request->ip())->response($tooManyAttempts),
                Limit::perMinute(config('rate_limits.login_per_ip'))->by($request->ip())->response($tooManyAttempts),
            ];
        });

        RateLimiter::for('password-reset', function (Request $request) use ($tooManyAttempts) {
            return config('rate_limits.enabled')
                ? Limit::perMinute(config('rate_limits.password_reset_per_ip'))->by($request->ip())->response($tooManyAttempts)
                : Limit::none();
        });

        RateLimiter::for('token-refresh', function (Request $request) use ($tooManyAttempts) {
            return config('rate_limits.enabled')
                ? Limit::perMinute(config('rate_limits.refresh_per_ip'))->by($request->ip())->response($tooManyAttempts)
                : Limit::none();
        });

        RateLimiter::for('api', function (Request $request) use ($tooManyAttempts) {
            if (! config('rate_limits.enabled')) {
                return Limit::none();
            }

            // Throttle chạy TRƯỚC middleware xác thực nên chưa có user — khóa theo
            // token của phiên (mỗi người 1 bộ đếm riêng, cả văn phòng chung 1 IP
            // không chặn lẫn nhau); chưa có token thì khóa theo IP.
            $token = $request->bearerToken();
            $limits = [
                Limit::perMinute(config('rate_limits.api_per_minute'))
                    ->by($token ? 'token:'.sha1($token) : 'ip:'.$request->ip())
                    ->response($tooManyAttempts),
            ];

            // 2026-10-14: token do client TỰ gửi, chưa kiểm tra thật/giả — gửi mỗi request
            // 1 token giả là có bộ đếm mới, không bao giờ bị chặn. Thêm trần chung theo IP
            // (tiền tố "ip-all:" để không trộn bộ đếm với giới hạn phía trên).
            if ($token) {
                $limits[] = Limit::perMinute(config('rate_limits.api_per_ip_per_minute'))
                    ->by('ip-all:'.$request->ip())
                    ->response($tooManyAttempts);
            }

            return $limits;
        });
    }
}
