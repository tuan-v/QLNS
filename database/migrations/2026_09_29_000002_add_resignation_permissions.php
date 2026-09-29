<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Thêm quyền cho "Đơn xin nghỉ việc" vào DB ĐANG CHẠY mà không phải chạy lại
// RolePermissionSeeder — seeder đó ĐỒNG BỘ THẬT (xóa quyền không có trong
// danh sách), chạy lại sẽ thu hồi mất các quyền HR đã tự chỉnh qua màn "Vai
// trò & Phân quyền". Migration này CHỈ THÊM, không xóa gì. Bản cài mới vẫn
// nhận đủ quyền qua PermissionSeeder/RolePermissionSeeder (đã cập nhật).
return new class () extends Migration {
    private const PERMISSIONS = [
        'resignation.request' => 'Nộp đơn xin nghỉ việc',
        'resignation.approve' => 'Duyệt đơn xin nghỉ việc',
        'resignation.view_all' => 'Xem & duyệt đơn nghỉ việc toàn công ty',
    ];

    private const ROLE_GRANTS = [
        'Admin' => ['resignation.request', 'resignation.approve', 'resignation.view_all'],
        'HR' => ['resignation.approve', 'resignation.view_all'],
        'Manager' => ['resignation.request', 'resignation.approve'],
        'Employee' => ['resignation.request'],
    ];

    public function up(): void
    {
        // Chạy trên DB rỗng (vd RefreshDatabase trong test) thì bỏ qua — seeder
        // sẽ tự tạo đủ quyền sau đó.
        if (! DB::table('roles')->exists()) {
            return;
        }

        foreach (self::PERMISSIONS as $code => $name) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code, 'guard_name' => 'api'],
                ['name' => $name, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id', 'code');
        $roleIds = DB::table('roles')->pluck('id', 'name');

        foreach (self::ROLE_GRANTS as $roleName => $codes) {
            if (! isset($roleIds[$roleName])) {
                continue;
            }
            foreach ($codes as $code) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleIds[$roleName], 'permission_id' => $permissionIds[$code]],
                    ['granted_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
