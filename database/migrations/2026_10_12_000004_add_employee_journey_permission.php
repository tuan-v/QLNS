<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Quyền xem tab "Hành trình" của nhân viên (tuyển dụng -> hợp đồng/lương/chức vụ -> lương
// thực nhận) cho DB ĐANG CHẠY — CHỈ THÊM. Admin + HR; bản cài mới nhận qua seeder.
return new class () extends Migration {
    public function up(): void
    {
        if (! DB::table('roles')->exists()) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['code' => 'employee.journey', 'guard_name' => 'api'],
            ['name' => 'Xem hành trình nhân viên (lương qua từng giai đoạn)', 'created_at' => now(), 'updated_at' => now()],
        );
        $permissionId = DB::table('permissions')->where('code', 'employee.journey')->value('id');

        foreach (DB::table('roles')->whereIn('name', ['Admin', 'HR'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['granted_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('code', 'employee.journey')->value('id');
        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
