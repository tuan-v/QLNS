@php
    $passed = $candidate->status === \App\Models\RecruitmentCandidate::STATUS_PASSED;
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Kết quả phỏng vấn</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f8fafc; padding:24px; color:#0f172a;">
    <div style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:12px; padding:32px; border:1px solid #e2e8f0;">
        <h2 style="margin-top:0;">Kết quả phỏng vấn</h2>
        <p>Xin chào {{ $candidate->full_name }},</p>
        <p>Cảm ơn bạn đã tham gia phỏng vấn vị trí <strong>{{ $candidate->opening->title }}</strong> tại {{ config('app.name') }}.</p>
        @if ($passed)
            <p><strong style="color:#16a34a;">Chúc mừng bạn đã vượt qua vòng phỏng vấn!</strong> Phòng Nhân sự sẽ sớm liên hệ để trao đổi về ngày nhận việc và các thủ tục cần thiết.</p>
        @else
            <p>Chúng tôi rất tiếc bạn chưa phù hợp với vị trí này ở thời điểm hiện tại. Chúng tôi sẽ lưu hồ sơ và liên hệ khi có cơ hội phù hợp hơn. Chúc bạn nhiều thành công!</p>
        @endif
        <p style="color:#64748b; font-size:0.85em;">Đây là email tự động, vui lòng không trả lời email này.</p>
    </div>
</body>
</html>
