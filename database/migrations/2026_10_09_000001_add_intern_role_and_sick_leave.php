<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Thực tập sinh (hợp đồng 'thuc_tap'): vai trò "Intern" chỉ chấm công (kèm xin điều
// chỉnh/bổ sung/OT), xin nghỉ ốm/nghỉ không lương, xem phiếu lương. Thêm cờ
// leave_types.allow_intern + loại "Nghỉ ốm" (công ty không trả lương — BHXH chi trả).
// CHỈ THÊM vào DB đang chạy; bản cài mới nhận qua RoleSeeder/RolePermissionSeeder/LeaveTypeSeeder.
return new class () extends Migration {
    private const INTERN_PERMISSIONS = [
        'attendance.check', 'attendance.view_own', 'leave.request', 'leave.view_own', 'payroll.view_own',
    ];

    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->boolean('allow_intern')->default(false)->after('is_paid');
        });

        if (! DB::table('roles')->exists()) {
            return;
        }

        DB::table('leave_types')->updateOrInsert(
            ['code' => 'sick'],
            [
                'name' => 'Nghỉ ốm', 'annual_entitlement_days' => 0, 'is_paid' => false, 'allow_intern' => true,
                'allow_carry_forward' => false, 'max_carry_forward_days' => null, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
        );
        DB::table('leave_types')->where('code', 'unpaid')->update(['allow_intern' => true]);

        DB::table('roles')->updateOrInsert(
            ['name' => 'Intern', 'guard_name' => 'api'],
            [
                'level' => 0,
                'description' => 'Thực tập sinh - chỉ chấm công, xin nghỉ ốm/không lương, xem phiếu lương',
                'created_at' => now(), 'updated_at' => now(),
            ],
        );
        $roleId = DB::table('roles')->where('name', 'Intern')->value('id');

        foreach (DB::table('permissions')->whereIn('code', self::INTERN_PERMISSIONS)->pluck('id') as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['granted_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('name', 'Intern')->value('id');
        if ($roleId) {
            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            DB::table('user_roles')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }

        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('allow_intern');
        });
    }
};
