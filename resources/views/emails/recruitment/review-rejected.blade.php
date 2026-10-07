<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Kết quả xét duyệt hồ sơ</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f8fafc; padding:24px; color:#0f172a;">
    <div style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:12px; padding:32px; border:1px solid #e2e8f0;">
        <h2 style="margin-top:0;">Kết quả xét duyệt hồ sơ</h2>
        <p>Xin chào {{ $candidate->full_name }},</p>
        <p>Cảm ơn bạn đã quan tâm và ứng tuyển vị trí <strong>{{ $candidate->opening->title }}</strong> tại {{ config('app.name') }}.</p>
        <p>Sau khi xem xét kỹ hồ sơ, chúng tôi rất tiếc hồ sơ của bạn chưa phù hợp với yêu cầu của vị trí này ở thời điểm hiện tại.</p>
        <p>Chúng tôi sẽ lưu lại thông tin và liên hệ khi có cơ hội phù hợp hơn. Chúc bạn nhiều thành công!</p>
        <p style="color:#64748b; font-size:0.85em;">Đây là email tự động, vui lòng không trả lời email này.</p>
    </div>
</body>
</html>
