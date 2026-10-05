<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $allPermissionCodes = DB::table('permissions')->pluck('id', 'code');

        $map = [
            'Admin' => $allPermissionCodes->keys()->all(), // Admin: toàn quyền

            'HR' => [
                'employee.view',
                'employee.create',
                'employee.update',
                'employee.delete',
                'department.view',
                'department.manage',
                'shift.view',
                'shift.manage',
                'attendance.view_all',
                'attendance.adjust',
                'attendance.approve',
                'leave.view_all',
                'leave.approve_hr',
                'resignation.approve',
                'resignation.view_all',
                'holiday.manage',
                'payroll.view_all',
                'payroll.manage',
                'report.view',
            ],

            'Manager' => [
                'employee.view',
                'department.view',
                'shift.view',
                'attendance.check',
                'attendance.view_own',
                'attendance.view_all',
                'leave.request',
                'leave.view_own',
                'leave.view_all',
                'leave.approve_manager',
                // Manager duyệt được đơn nghỉ việc của nhân viên mình quản lý
                // trực tiếp (không có view_all — xem ResignationService).
                'resignation.request',
                'resignation.approve',
                'payroll.view_own',
                'report.view',
            ],

            // Không có employee.view — nhân viên thường chỉ xem được hồ sơ CHÍNH
            // MÌNH qua /employees/me (route riêng, không cần mã quyền này, xem
            // EmployeeController::me() ở CODE_MAP mục 8), không xem được danh
            // sách/hồ sơ đồng nghiệp khác.
            'Employee' => [
                'attendance.check',
                'attendance.view_own',
                'leave.request',
                'leave.view_own',
                'resignation.request',
                'payroll.view_own',
            ],
        ];

        $roles = DB::table('roles')->pluck('id', 'name');

        // Bọc cả vòng lặp trong 1 transaction — mỗi vòng vừa cấp (updateOrInsert)
        // vừa thu hồi (delete) quyền của 1 role; không bọc thì seeder chết giữa
        // chừng (vd lỗi 1 role) sẽ để lại role_permissions ở trạng thái nửa
        // đồng bộ (role trước đã xóa xong quyền cũ, role sau chưa chạy tới).
        DB::transaction(function () use ($map, $allPermissionCodes, $roles): void {
            foreach ($map as $roleName => $codes) {
                $roleId = $roles[$roleName];
                $permissionIds = collect($codes)
                    ->filter(fn (string $code) => isset($allPermissionCodes[$code]))
                    ->map(fn (string $code) => $allPermissionCodes[$code]);

                foreach ($permissionIds as $permissionId) {
                    DB::table('role_permissions')->updateOrInsert(
                        ['role_id' => $roleId, 'permission_id' => $permissionId],
                        ['granted_at' => now()]
                    );
                }

                // Đồng bộ THẬT SỰ, không chỉ cộng dồn: xóa quyền cũ không còn trong
                // $map ở trên — nếu không, bỏ 1 dòng code khỏi $map rồi chạy lại
                // seeder vẫn không thu hồi được quyền đã cấp trước đó trong DB.
                DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->whereNotIn('permission_id', $permissionIds)
                    ->delete();
            }
        });
    }
}
