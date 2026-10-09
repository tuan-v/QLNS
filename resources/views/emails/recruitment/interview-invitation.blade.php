@php
    $candidate = $interview->candidate;
    $isOnline = $interview->format === \App\Models\RecruitmentInterview::FORMAT_ONLINE;
    // Chỉ hiện link bấm được khi là http(s) — tránh chèn giao thức lạ vào email.
    $isLink = $isOnline && preg_match('#^https?://#i', (string) $interview->location);
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>{{ $rescheduled ? 'Thay đổi lịch phỏng vấn' : 'Thư mời phỏng vấn' }}</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f8fafc; padding:24px; color:#0f172a;">
    <div style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:12px; padding:32px; border:1px solid #e2e8f0;">
        <h2 style="margin-top:0;">{{ $rescheduled ? 'Thay đổi lịch phỏng vấn' : 'Thư mời phỏng vấn' }}</h2>
        <p>Xin chào {{ $candidate->full_name }},</p>
        @if ($rescheduled)
            <p>Lịch phỏng vấn vị trí <strong>{{ $candidate->opening->title }}</strong> của bạn đã được thay đổi. Thông tin mới:</p>
        @else
            <p>Chúc mừng bạn! Hồ sơ ứng tuyển vị trí <strong>{{ $candidate->opening->title }}</strong> tại {{ config('app.name') }} đã được chọn vào vòng phỏng vấn.</p>
        @endif
        <table style="width:100%; border-collapse:collapse; margin:16px 0;">
            <tr>
                <td style="padding:6px 0; color:#64748b; width:120px;">Thời gian</td>
                <td style="padding:6px 0;"><strong>{{ $interview->scheduled_at->timezone(config('app.timezone'))->format('H:i, d/m/Y') }}</strong></td>
            </tr>
            <tr>
                <td style="padding:6px 0; color:#64748b;">Hình thức</td>
                <td style="padding:6px 0;">{{ $isOnline ? 'Trực tuyến' : 'Trực tiếp' }}</td>
            </tr>
            <tr>
                <td style="padding:6px 0; color:#64748b;">{{ $isOnline ? 'Link' : 'Địa điểm' }}</td>
                <td style="padding:6px 0; word-break:break-all;">
                    @if ($isLink)
                        <a href="{{ $interview->location }}">{{ $interview->location }}</a>
                    @else
                        {{ $interview->location }}
                    @endif
                </td>
            </tr>
        </table>
        <p>Vui lòng có mặt đúng giờ. Nếu không thể tham gia, hãy liên hệ phòng Nhân sự để được hỗ trợ đổi lịch.</p>
        <p style="color:#64748b; font-size:0.85em;">Đây là email tự động, vui lòng không trả lời email này.</p>
    </div>
</body>
</html>
