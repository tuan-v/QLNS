<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('presence.online-employees', function (User $user) {
    // Bất kỳ user đã đăng nhập nào cũng được XEM danh sách online (kể cả tài
    // khoản hệ thống như Admin/HR không gắn hồ sơ Employee riêng) — chỉ user
    // CÓ employee mới thật sự "hiện chấm xanh" cho người khác thấy (employee_id
    // null thì Employees.vue không khớp được với dòng nào trong bảng).
    $employee = $user->employee;

    return [
        'employee_id' => $employee?->id,
        'full_name' => $employee?->full_name ?? $user->user_name,
        'avatar' => $employee?->avatar,
    ];
});

// Thông báo cá nhân (mục "Thông báo" CODE_MAP) — kênh riêng cho MỖI user,
// chỉ chính chủ mới nghe được thông báo của mình.
Broadcast::channel('notifications.{userId}', function (User $user, int $userId) {
    return (int) $user->id === $userId;
});

// Live-feed chấm công cho HR xem trực tiếp (KHÔNG lưu DB, xem AttendanceChecked)
// — chỉ user có quyền attendance.view_all mới xem được.
Broadcast::channel('attendance.live-feed', function (User $user) {
    return in_array('attendance.view_all', $user->cachedPermissionCodes(), true);
});
