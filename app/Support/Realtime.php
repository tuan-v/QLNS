<?php

namespace App\Support;

use App\Events\ResourceChanged;
use App\Events\UserDataChanged;
use App\Models\Employee;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

// Cửa duy nhất để báo "dữ liệu vừa đổi" cho trình duyệt đang mở (WebSocket).
// - shared(): báo mọi người có quyền xem 1 trang quản lý (kênh resource-sync).
// - forUser()/forEmployee(): báo riêng chủ dữ liệu (kênh user-sync).
// Trong request HTTP, tín hiệu được GOM + LOẠI TRÙNG rồi mới gửi SAU KHI đã
// trả response (lúc đó mọi ghi DB đã commit, client tải lại sẽ thấy dữ liệu
// mới; thao tác hàng loạt như tạo bảng lương không bắn hàng trăm lần). Ngoài
// HTTP (Artisan/queue) gửi sau commit; khi chạy test gửi ngay.
// Lỗi broadcast (vd Reverb tắt) KHÔNG bao giờ được làm hỏng thao tác chính.
final class Realtime
{
    /** @var array<string, Closure> */
    private static array $pending = [];

    private static bool $flushScheduled = false;

    /** @var array<int, int|null> */
    private static array $userIdByEmployee = [];

    public static function shared(string $resource): void
    {
        self::queue("s:{$resource}", fn () => ResourceChanged::dispatch($resource));
    }

    public static function forUser(int $userId, string $resource): void
    {
        self::queue("u:{$userId}:{$resource}", fn () => UserDataChanged::dispatch($userId, $resource));
    }

    public static function forEmployee(int $employeeId, string $resource): void
    {
        // Cache chỉ trong 1 request HTTP (flush() xóa) — worker/test chạy lâu
        // hoặc lặp id giữa các test sẽ đọc nhầm nếu giữ cache.
        $lookup = fn () => Employee::withTrashed()->whereKey($employeeId)->value('user_id');
        $userId = app()->runningInConsole()
            ? $lookup()
            : (self::$userIdByEmployee[$employeeId] ??= $lookup());

        if ($userId !== null) {
            self::forUser((int) $userId, $resource);
        }
    }

    public static function flush(): void
    {
        $pending = self::$pending;
        self::$pending = [];
        self::$flushScheduled = false;
        self::$userIdByEmployee = [];

        foreach ($pending as $dispatch) {
            self::send($dispatch);
        }
    }

    private static function queue(string $key, Closure $dispatch): void
    {
        if (app()->runningUnitTests()) {
            self::send($dispatch);

            return;
        }

        if (app()->runningInConsole()) {
            DB::afterCommit(fn () => self::send($dispatch));

            return;
        }

        self::$pending[$key] = $dispatch;

        if (! self::$flushScheduled) {
            self::$flushScheduled = true;
            app()->terminating(fn () => self::flush());
        }
    }

    private static function send(Closure $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
