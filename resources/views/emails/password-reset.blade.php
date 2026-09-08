<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Đặt lại mật khẩu QLNS</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f8fafc; padding:24px; color:#0f172a;">
    <div style="max-width:480px; margin:0 auto; background:#ffffff; border-radius:12px; padding:32px; border:1px solid #e2e8f0;">
        <h2 style="margin-top:0;">Yêu cầu đặt lại mật khẩu</h2>
        <p>Xin chào {{ $user->user_name }},</p>
        <p>Chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản <strong>{{ $user->email }}</strong>. Bấm nút bên dưới để đặt mật khẩu mới:</p>
        <p style="text-align:center; margin:32px 0;">
            <a href="{{ $resetUrl }}" style="background:#6366f1; color:#ffffff; padding:12px 24px; border-radius:8px; text-decoration:none; font-weight:600;">
                Đặt lại mật khẩu
            </a>
        </p>
        <p>Liên kết có hiệu lực trong 60 phút. Nếu bạn không yêu cầu đổi mật khẩu, hãy bỏ qua email này — mật khẩu hiện tại vẫn giữ nguyên.</p>
        <p style="color:#64748b; font-size:0.85em; word-break:break-all;">Nếu nút bấm không hoạt động, dán liên kết sau vào trình duyệt:<br>{{ $resetUrl }}</p>
    </div>
</body>
</html>
