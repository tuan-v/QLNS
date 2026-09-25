<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// 2026-09-25, theo yêu cầu người dùng: "làm toàn bộ trang cũng có realtime" —
// mở rộng mục 34 CODE_MAP (trước đó chỉ làm riêng Tổng hợp chấm công/Duyệt
// nghỉ phép, mỗi trang 1 Event tay) ra TOÀN BỘ các trang danh sách/quản lý
// còn lại bằng 1 Event DÙNG CHUNG duy nhất, tham số hóa qua $resource — tránh
// phải viết lại 1 class Event riêng cho mỗi trang. KHÔNG mang dữ liệu thật,
// CHỈ là 1 "tiếng chuông" báo ai đang mở đúng trang đó tự gọi lại API load
// danh sách — nên không cần quan tâm race condition/thứ tự, không lưu DB.
class ResourceChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly string $resource)
    {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("resource-sync.{$this->resource}")];
    }

    public function broadcastAs(): string
    {
        return 'resource.changed';
    }
}
