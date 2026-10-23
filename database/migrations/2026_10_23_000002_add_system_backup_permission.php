<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Quyền sao lưu dữ liệu (xem / tạo / tải bản sao lưu) cho DB ĐANG CHẠY — CHỈ THÊM, chỉ Admin. Bản cài mới nhận qua seeder.
return new class () extends Migration {
    public function up(): void
    {
        if (! DB::table('roles')->exists()) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['code' => 'system.backup', 'guard_name' => 'api'],
            ['name' => 'Sao lưu dữ liệu (xem, tạo, tải bản sao lưu)', 'created_at' => now(), 'updated_at' => now()],
        );
        $permissionId = DB::table('permissions')->where('code', 'system.backup')->value('id');

        foreach (DB::table('roles')->where('name', 'Admin')->pluck('id') as $roleId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['granted_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('code', 'system.backup')->value('id');
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
