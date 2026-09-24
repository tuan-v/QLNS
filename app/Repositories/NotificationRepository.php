<?php

namespace App\Repositories;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationRepository
{
    public function paginateForUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return Notification::where('user_id', $user->id)->latest()->paginate($perPage);
    }

    public function unreadCountForUser(User $user): int
    {
        return Notification::where('user_id', $user->id)->whereNull('read_at')->count();
    }

    // Chỉ tìm trong đúng thông báo của $user — không cho đọc/đánh dấu thông
    // báo của người khác dù biết đúng UUID.
    public function findForUser(User $user, string $id): ?Notification
    {
        return Notification::where('user_id', $user->id)->find($id);
    }

    public function create(array $data): Notification
    {
        return Notification::create($data);
    }

    public function createDelivery(array $data): NotificationDelivery
    {
        return NotificationDelivery::create($data);
    }

    public function markRead(Notification $notification): Notification
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification;
    }

    public function markAllRead(User $user): void
    {
        Notification::where('user_id', $user->id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    // Toàn bộ thông báo trong công ty, không lọc theo user_id (dùng cho
    // trang admin "Toàn công ty", gate notification.view_all) — nạp sẵn
    // user.employee để hiển thị "gửi cho ai" mà không bị N+1.
    public function paginateAll(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return Notification::with('user.employee')
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->whereHas('user.employee', fn ($eq) => $eq->where('full_name', 'like', "%{$search}%"));
            })
            ->latest()
            ->paginate($perPage);
    }
}
