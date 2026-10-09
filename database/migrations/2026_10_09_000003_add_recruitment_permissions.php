<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Quyền tuyển dụng cho DB ĐANG CHẠY — CHỈ THÊM. Bản cài mới nhận qua
// PermissionSeeder/RolePermissionSeeder. recruitment.manage: HR + Admin (đợt tuyển,
// tải CV, hẹn phỏng vấn, ghi kết quả); recruitment.approve: chỉ Admin (duyệt CV).
return new class () extends Migration {
    private const PERMISSIONS = [
        'recruitment.manage' => ['name' => 'Quản lý tuyển dụng (đợt tuyển, CV, phỏng vấn)', 'roles' => ['Admin', 'HR']],
        'recruitment.approve' => ['name' => 'Duyệt CV ứng viên', 'roles' => ['Admin']],
    ];

    public function up(): void
    {
        if (! DB::table('roles')->exists()) {
            return;
        }

        foreach (self::PERMISSIONS as $code => $config) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code, 'guard_name' => 'api'],
                ['name' => $config['name'], 'created_at' => now(), 'updated_at' => now()],
            );
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');

            foreach (DB::table('roles')->whereIn('name', $config['roles'])->pluck('id') as $roleId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
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
