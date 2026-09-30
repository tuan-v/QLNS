<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// Tín hiệu "dữ liệu CỦA BẠN vừa đổi" gửi riêng cho 1 user (kênh
// user-sync.{userId}) — để trang tự phục vụ (Dashboard, Chấm công, Nghỉ phép,
// Hồ sơ của tôi...) tự tải lại mà không cần F5. Rỗng, không mang dữ liệu thật,
// không lưu DB. Khác ResourceChanged (báo cho MỌI người có quyền xem 1 trang
// quản lý) — xem App\Support\Realtime.
class UserDataChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $resource,
    ) {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("user-sync.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'user-data.changed';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['resource' => $this->resource];
    }
}
