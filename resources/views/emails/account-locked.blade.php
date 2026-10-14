<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Tài khoản QLNS tạm khóa</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f8fafc; padding:24px; color:#0f172a;">
    <div style="max-width:480px; margin:0 auto; background:#ffffff; border-radius:12px; padding:32px; border:1px solid #e2e8f0;">
        <h2 style="margin-top:0; color:#b91c1c;">Tài khoản tạm khóa</h2>
        <p>Xin chào {{ $user->user_name }},</p>
        <p>Tài khoản <strong>{{ $user->email }}</strong> vừa bị nhập sai mật khẩu nhiều lần liên tiếp nên hệ thống đã <strong>tạm khóa đăng nhập trong {{ $minutes }} phút</strong> để bảo vệ tài khoản.</p>
        <p style="background:#f1f5f9; border-radius:8px; padding:12px 16px; font-size:0.9em;">
            Thời điểm: {{ $lockedAt }}<br>
            Địa chỉ IP lần thử cuối: {{ $ip ?? 'không xác định' }}
        </p>
        <p><strong>Nếu đó là bạn</strong> (quên mật khẩu): chờ hết thời gian khóa rồi thử lại, hoặc đặt lại mật khẩu ngay — đặt lại xong là mở khóa luôn.</p>
        <p><strong>Nếu không phải bạn</strong>: có người đang cố đoán mật khẩu của bạn. Hãy đặt lại mật khẩu mạnh hơn và báo cho quản trị viên.</p>
        <p style="text-align:center; margin:32px 0;">
            <a href="{{ $forgotUrl }}" style="background:#6366f1; color:#ffffff; padding:12px 24px; border-radius:8px; text-decoration:none; font-weight:600;">
                Đặt lại mật khẩu
            </a>
        </p>
    </div>
</body>
</html>
