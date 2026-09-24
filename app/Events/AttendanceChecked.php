<?php

namespace App\Events;

use App\Models\Employee;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Sự kiện thuần cho HR xem trực tiếp (live-feed) trên trang Tổng quan chấm
// công — KHÔNG lưu DB, KHÔNG đi qua NotificationService (khác thông báo cá
// nhân dạng đọc/chưa đọc). Nếu lưu vào bảng notifications sẽ spam hộp thư
// của mọi HR mỗi lần BẤT KỲ ai chấm công.
class AttendanceChecked implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Employee $employee,
        public readonly string $type,
        public readonly \DateTimeInterface $at,
    ) {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('attendance.live-feed')];
    }

    public function broadcastAs(): string
    {
        return 'attendance.checked';
    }

    public function broadcastWith(): array
    {
        return [
            'employee_id' => $this->employee->id,
            'full_name' => $this->employee->full_name,
            'avatar' => $this->employee->avatar,
            'type' => $this->type,
            'at' => $this->at->format(\DateTimeInterface::ATOM),
        ];
    }
}
