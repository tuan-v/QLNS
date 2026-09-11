<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <title>Thông báo kết quả đơn xin nghỉ phép</title>
</head>

<body style="font-family: Arial, sans-serif; background:#f8fafc; padding:24px; color:#0f172a;">
    <div style="max-width:480px; margin:0 auto; background:#ffffff; border-radius:12px; padding:32px; border:1px solid #e2e8f0;">
        @php
            $isApproved = $leaveRequest->status === 'approved';
        @endphp

        <h2 style="margin-top:0;">Kết quả đơn xin nghỉ phép</h2>
        <p>Xin chào {{ $leaveRequest->employee->full_name }},</p>
        <p>Đơn xin nghỉ phép của bạn đã có kết quả:</p>

        <p style="text-align:center; margin:24px 0;">
            <span style="display:inline-block; padding:8px 20px; border-radius:999px; font-weight:600;
                background:{{ $isApproved ? '#dcfce7' : '#fee2e2' }};
                color:{{ $isApproved ? '#166534' : '#991b1b' }};">
                {{ $isApproved ? 'Đã duyệt' : 'Đã từ chối' }}
            </span>
        </p>

        <table style="width:100%; border-collapse:collapse; margin-bottom:24px;">
            <tr>
                <td style="padding:6px 0; color:#64748b;">Loại phép</td>
                <td style="padding:6px 0; text-align:right; font-weight:600;">{{ $leaveRequest->leaveType->name }}</td>
            </tr>
            <tr>
                <td style="padding:6px 0; color:#64748b;">Từ ngày</td>
                <td style="padding:6px 0; text-align:right; font-weight:600;">{{ $leaveRequest->from_date->format('d/m/Y') }}</td>
            </tr>
            <tr>
                <td style="padding:6px 0; color:#64748b;">Đến ngày</td>
                <td style="padding:6px 0; text-align:right; font-weight:600;">{{ $leaveRequest->to_date->format('d/m/Y') }}</td>
            </tr>
            <tr>
                <td style="padding:6px 0; color:#64748b;">Số ngày phép</td>
                <td style="padding:6px 0; text-align:right; font-weight:600;">{{ $leaveRequest->total_days }}</td>
            </tr>
        </table>

        @if($comment)
            <p style="background:#f1f5f9; border-radius:8px; padding:12px 16px; margin-bottom:0;">
                <strong>{{ $isApproved ? 'Ghi chú' : 'Lý do từ chối' }}:</strong> {{ $comment }}
            </p>
        @endif

        <p style="color:#64748b; font-size:0.85em; margin-top:24px;">Đây là email tự động từ hệ thống QLNS, vui lòng không trả lời email này.</p>
    </div>
</body>

</html>
