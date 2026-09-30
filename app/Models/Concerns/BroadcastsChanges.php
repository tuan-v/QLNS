<?php

namespace App\Models\Concerns;

use App\Support\Realtime;
use Illuminate\Database\Eloquent\Model;

// Gắn vào Model để MỖI lần tạo/sửa/xóa/khôi phục tự báo realtime — không cần
// nhớ gọi tay ở từng Service (nguyên nhân các trang phải F5 trước đây).
// Model khai báo:
//   protected array $realtimeShared = ['attendances'];  // trang quản lý (resource-sync.*)
//   protected array $realtimeOwn = ['attendance'];      // trang tự phục vụ của chủ dữ liệu (user-sync.*)
// Chủ dữ liệu = cột employee_id (ghi đè realtimeEmployeeIds() nếu khác).
// Lưu ý: ->update() bằng query builder KHÔNG kích hoạt event Eloquent — chỗ
// nào dùng thì phải gọi Realtime::* tay.
trait BroadcastsChanges
{
    protected static function bootBroadcastsChanges(): void
    {
        $notify = static fn (Model $model) => $model->broadcastRealtimeChange();

        // Không dùng "saved": increment()/decrement() chỉ phát "updated".
        static::created($notify);
        static::updated($notify);
        static::deleted($notify);
        static::registerModelEvent('restored', $notify);
    }

    public function broadcastRealtimeChange(): void
    {
        foreach ($this->realtimeShared ?? [] as $resource) {
            Realtime::shared($resource);
        }

        $own = $this->realtimeOwn ?? [];
        if ($own === []) {
            return;
        }

        foreach ($this->realtimeEmployeeIds() as $employeeId) {
            foreach ($own as $resource) {
                Realtime::forEmployee((int) $employeeId, $resource);
            }
        }
    }

    /** @return array<int, int> */
    protected function realtimeEmployeeIds(): array
    {
        return $this->employee_id ? [(int) $this->employee_id] : [];
    }
}
