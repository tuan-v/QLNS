<?php

// Giới hạn số lần gọi API (2026-09-30, Kế hoạch Ngày 61 — "Rate Limiting chi tiết
// cho từng API"; trạng thái đếm lưu ở cache/Redis). Đăng ký ở AppServiceProvider,
// gắn vào route bằng middleware `throttle:<tên>`. Tắt bằng RATE_LIMITS_ENABLED=false
// (phpunit.xml tắt để test không tự chặn nhau).
return [
    'enabled' => (bool) env('RATE_LIMITS_ENABLED', true),

    // Đăng nhập: chống dò mật khẩu — theo cặp email+IP và theo IP.
    'login_per_email' => (int) env('RATE_LIMIT_LOGIN_PER_EMAIL', 5),
    'login_per_ip' => (int) env('RATE_LIMIT_LOGIN_PER_IP', 20),

    // Quên / đặt lại mật khẩu: chống spam email và dò token.
    'password_reset_per_ip' => (int) env('RATE_LIMIT_PASSWORD_RESET_PER_IP', 5),

    // Làm mới token (trình duyệt tự gọi khi access token hết hạn).
    'refresh_per_ip' => (int) env('RATE_LIMIT_REFRESH_PER_IP', 30),

    // Mọi API còn lại — theo user đã đăng nhập (hoặc IP).
    'api_per_minute' => (int) env('RATE_LIMIT_API_PER_MINUTE', 600),
];
