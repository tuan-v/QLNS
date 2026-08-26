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
                'employee.view', 'employee.create', 'employee.update', 'employee.delete',
                'department.view', 'department.manage',
                'attendance.view_all', 'attendance.adjust',
                'leave.view_all', 'leave.approve',
                'payroll.view_all', 'payroll.manage',
                'report.view',
            ],

            'Manager' => [
                'employee.view',
                'department.view',
                'attendance.check', 'attendance.view_own', 'attendance.view_all',
                'leave.request', 'leave.view_own', 'leave.view_all', 'leave.approve',
                'payroll.view_own',
                'report.view',
            ],

            'Employee' => [
                'employee.view',
                'attendance.check', 'attendance.view_own',
                'leave.request', 'leave.view_own',
                'payroll.view_own',
            ],
        ];

        $roles = DB::table('roles')->pluck('id', 'name');

        foreach ($map as $roleName => $codes) {
            $roleId = $roles[$roleName];

            foreach ($codes as $code) {
                if (!isset($allPermissionCodes[$code])) {
                    continue;
                }

                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $allPermissionCodes[$code]],
                    ['granted_at' => now()]
                );
            }
        }
    }
}
