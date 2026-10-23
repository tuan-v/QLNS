<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// HR cũng là nhân viên: được tự chấm công và xin nghỉ phép (đơn của HR chỉ Admin duyệt — ApprovalPolicy).
// Cấp cho DB ĐANG CHẠY — CHỈ THÊM quyền vào vai trò HR; bản cài mới nhận qua seeder.
return new class () extends Migration {
    private const CODES = ['attendance.check', 'attendance.view_own', 'leave.request', 'leave.view_own'];

    public function up(): void
    {
        $hrRoleId = DB::table('roles')->where('name', 'HR')->value('id');
        if ($hrRoleId === null) {
            return;
        }

        foreach (DB::table('permissions')->whereIn('code', self::CODES)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $hrRoleId, 'permission_id' => $permissionId],
                ['granted_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $hrRoleId = DB::table('roles')->where('name', 'HR')->value('id');
        $permissionIds = DB::table('permissions')->whereIn('code', self::CODES)->pluck('id');

        DB::table('role_permissions')->where('role_id', $hrRoleId)->whereIn('permission_id', $permissionIds)->delete();
    }
};
