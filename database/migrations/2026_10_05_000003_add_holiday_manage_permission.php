<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Thêm quyền "holiday.manage" vào DB ĐANG CHẠY — CHỈ THÊM, không chạy lại
// RolePermissionSeeder (seeder đó đồng bộ thật, sẽ xóa quyền HR tự chỉnh qua giao
// diện). Bản cài mới nhận quyền qua PermissionSeeder/RolePermissionSeeder.
return new class () extends Migration {
    private const CODE = 'holiday.manage';

    private const ROLES = ['Admin', 'HR'];

    public function up(): void
    {
        if (! DB::table('roles')->exists()) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['code' => self::CODE, 'guard_name' => 'api'],
            ['name' => 'Quản lý ngày nghỉ lễ', 'created_at' => now(), 'updated_at' => now()],
        );

        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        foreach (DB::table('roles')->whereIn('name', self::ROLES)->pluck('id') as $roleId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['granted_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('code', self::CODE)->value('id');
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
