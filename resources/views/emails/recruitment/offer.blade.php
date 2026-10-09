@php
    $candidate = $offer->candidate;
    $opening = $candidate->opening;
    $contractLabels = ['thuc_tap' => 'Thực tập', 'thu_viec' => 'Thử việc', 'chinh_thuc' => 'Chính thức'];
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Thư mời nhận việc</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f8fafc; padding:24px; color:#0f172a;">
    <div style="max-width:560px; margin:0 auto; background:#ffffff; border-radius:12px; padding:32px; border:1px solid #e2e8f0;">
        <h2 style="margin-top:0;">Thư mời nhận việc</h2>
        <p>Xin chào {{ $candidate->full_name }},</p>
        <p>Chúc mừng bạn! {{ config('app.name') }} trân trọng mời bạn nhận vị trí <strong>{{ $opening->title }}</strong> với các điều kiện sau:</p>
        <table style="width:100%; border-collapse:collapse; margin:16px 0;">
            <tr><td style="padding:6px 0; color:#64748b;">Loại hợp đồng</td><td style="padding:6px 0;"><strong>{{ $contractLabels[$offer->contract_type] ?? $offer->contract_type }}</strong></td></tr>
            <tr><td style="padding:6px 0; color:#64748b;">Mức lương</td><td style="padding:6px 0;"><strong>{{ number_format((float) $offer->salary, 0, ',', '.') }} ₫/tháng</strong></td></tr>
            <tr><td style="padding:6px 0; color:#64748b;">Ngày bắt đầu</td><td style="padding:6px 0;"><strong>{{ $offer->start_date->format('d/m/Y') }}</strong></td></tr>
            <tr><td style="padding:6px 0; color:#64748b;">Hạn trả lời</td><td style="padding:6px 0;"><strong>{{ $offer->response_deadline->format('d/m/Y') }}</strong></td></tr>
        </table>
        @if ($offer->message)
            <p style="white-space:pre-line; background:#f1f5f9; border-radius:8px; padding:12px;">{{ $offer->message }}</p>
        @endif
        <p>Vui lòng xác nhận trước hạn trả lời bằng cách bấm nút dưới đây:</p>
        <p style="text-align:center; margin:24px 0;">
            <a href="{{ $responseUrl }}" style="background:#4f46e5; color:#ffffff; text-decoration:none; padding:12px 24px; border-radius:8px; display:inline-block; font-weight:bold;">Xem và trả lời thư mời</a>
        </p>
        <p style="color:#64748b; font-size:0.85em;">Link chỉ dành riêng cho bạn, vui lòng không chia sẻ. Sau hạn trả lời, thư mời sẽ hết hiệu lực.</p>
        <p style="color:#64748b; font-size:0.85em;">Đây là email tự động, vui lòng không trả lời email này.</p>
    </div>
</body>
</html>
