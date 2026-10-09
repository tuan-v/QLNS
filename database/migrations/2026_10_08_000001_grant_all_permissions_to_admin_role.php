<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Vai trò Admin luôn có TOÀN BỘ quyền (RoleService chặn bỏ quyền của Admin) — cấp
// bù mọi quyền Admin đang thiếu trên DB đang chạy. CHỈ THÊM, không xóa gì.
return new class () extends Migration {
    public function up(): void
    {
        $adminId = DB::table('roles')->where('name', 'Admin')->whereNull('deleted_at')->value('id');

        if ($adminId === null) {
            return;
        }

        $granted = DB::table('role_permissions')->where('role_id', $adminId)->pluck('permission_id')->all();

        $rows = DB::table('permissions')
            ->whereNull('deleted_at')
            ->whereNotIn('id', $granted)
            ->pluck('id')
            ->map(fn ($id) => ['role_id' => $adminId, 'permission_id' => $id, 'granted_at' => now()])
            ->all();

        if ($rows !== []) {
            DB::table('role_permissions')->insert($rows);
        }
    }

    public function down(): void
    {
        // Không gỡ: không phân biệt được quyền nào do migration này cấp.
    }
};
