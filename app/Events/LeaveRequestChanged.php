<?php

namespace App\Events;

use App\Models\LeaveRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// 2026-09-25, theo yêu cầu người dùng: "duyệt ... nhưng bên tài khoản nhân sự
// phải F5 lại mới thấy" — bắn khi 1 đơn nghỉ phép được TẠO MỚI hoặc ĐỔI TRẠNG
// THÁI (LeaveRequestService::create(), LeaveApprovalService::decide()) để báo
// cho các phiên Manager/HR KHÁC đang mở sẵn trang "Duyệt nghỉ phép" tự làm
// mới danh sách. KHÁC thông báo cá nhân (NotificationService) vốn chỉ tới
// ĐÚNG người liên quan ở đúng cấp duyệt — sự kiện này tới TẤT CẢ ai có quyền
// duyệt, kể cả người không phải người nhận thông báo cá nhân lần này (vd HR B
// khi HR A vừa duyệt xong đơn ở cấp cuối).
class LeaveRequestChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly LeaveRequest $leaveRequest)
    {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('leave-requests.live-feed')];
    }

    public function broadcastAs(): string
    {
        return 'leave-request.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'leave_request_id' => $this->leaveRequest->id,
            'status' => $this->leaveRequest->status,
        ];
    }
}
