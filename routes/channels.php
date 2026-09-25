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

// Live-feed chấm công cho HR xem trực tiếp (KHÔNG lưu DB, xem AttendanceChecked
// và AttendanceApprovalDecided) — chỉ user có quyền attendance.view_all mới
// xem được.
Broadcast::channel('attendance.live-feed', function (User $user) {
    return in_array('attendance.view_all', $user->cachedPermissionCodes(), true);
});

// Live-feed đơn nghỉ phép cho Manager/HR xem trực tiếp (KHÔNG lưu DB, xem
// LeaveRequestChanged) — chỉ user có quyền duyệt ở 1 trong 2 cấp mới xem được.
Broadcast::channel('leave-requests.live-feed', function (User $user) {
    $permissions = $user->cachedPermissionCodes();

    return in_array('leave.approve_manager', $permissions, true)
        || in_array('leave.approve_hr', $permissions, true);
});

// "Trang quản lý tự làm mới realtime" (mục 34 CODE_MAP, mở rộng 2026-09-25 —
// theo yêu cầu người dùng "làm toàn bộ trang cũng có realtime") — 1 kênh
// DÙNG CHUNG cho mọi trang danh sách/quản lý còn lại, tham số hóa qua
// {resource} thay vì mở 1 kênh riêng cho từng trang (xem ResourceChanged.php).
// Bảng ánh xạ dưới đây PHẢI khớp đúng quyền route tương ứng khai ở
// resources/js/router/index.js (meta.permission) — sai thì rò rỉ hoặc chặn
// nhầm quyền xem tín hiệu làm mới, dù dữ liệu thật vẫn do API REST bảo vệ
// riêng (kênh này không tự trả dữ liệu, chỉ báo "có gì đó vừa đổi").
Broadcast::channel('resource-sync.{resource}', function (User $user, string $resource) {
    $requiredPermission = match ($resource) {
        'departments', 'positions' => 'department.view',
        'roles' => 'rbac.manage',
        'payrolls' => 'payroll.view_all',
        'employees' => 'employee.view',
        'work_shifts' => 'shift.view',
        'attendance_locations' => 'location.view',
        'attendance_adjustments' => 'attendance.adjust',
        default => null,
    };

    return $requiredPermission !== null && in_array($requiredPermission, $user->cachedPermissionCodes(), true);
});
