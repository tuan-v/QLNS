<?php

namespace App\Events;

use App\Models\Attendance;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// 2026-09-25, theo yêu cầu người dùng: "duyệt chấm công ở admin nhưng bên tài
// khoản nhân sự phải F5 lại mới thấy" — bắn khi TRẠNG THÁI DUYỆT của 1 bản ghi
// chấm công đổi (AttendanceService::decideApproval()) để báo cho các phiên
// HR/Manager KHÁC đang mở sẵn trang "Tổng hợp chấm công" tự làm mới danh sách.
// Dùng LẠI kênh 'attendance.live-feed' đã có (cùng quyền attendance.view_all,
// cùng audience với AttendanceChecked) thay vì mở kênh mới — không lưu DB,
// không đi qua NotificationService (khác thông báo cá nhân đọc/chưa đọc).
class AttendanceApprovalDecided implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly Attendance $attendance)
    {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('attendance.live-feed')];
    }

    public function broadcastAs(): string
    {
        return 'attendance.approval-decided';
    }

    public function broadcastWith(): array
    {
        return [
            'attendance_id' => $this->attendance->id,
            'attendance_date' => $this->attendance->attendance_date->toDateString(),
            'approval_status' => $this->attendance->approval_status,
        ];
    }
}
