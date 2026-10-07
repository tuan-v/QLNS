<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Quyền Onboarding/Offboarding cho DB ĐANG CHẠY — CHỈ THÊM. Bản cài mới nhận qua
// PermissionSeeder/RolePermissionSeeder. onboarding.manage: HR + Admin (mẫu checklist,
// tạo/hủy checklist, đánh dấu mọi việc); onboarding.team: Manager (xem checklist của
// nhân viên mình quản lý trực tiếp, đánh dấu việc phần "Quản lý").
return new class () extends Migration {
    private const PERMISSIONS = [
        'onboarding.manage' => ['name' => 'Quản lý onboarding/offboarding (mẫu & checklist)', 'roles' => ['Admin', 'HR']],
        'onboarding.team' => ['name' => 'Theo dõi onboarding/offboarding của nhân viên mình quản lý', 'roles' => ['Admin', 'Manager']],
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
