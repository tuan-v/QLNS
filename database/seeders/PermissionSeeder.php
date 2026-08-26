<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Employee management
            ['code' => 'employee.view', 'name' => 'Xem hồ sơ nhân viên'],
            ['code' => 'employee.create', 'name' => 'Tạo hồ sơ nhân viên'],
            ['code' => 'employee.update', 'name' => 'Cập nhật hồ sơ nhân viên'],
            ['code' => 'employee.delete', 'name' => 'Xóa hồ sơ nhân viên'],

            // Department & Position
            ['code' => 'department.view', 'name' => 'Xem phòng ban'],
            ['code' => 'department.manage', 'name' => 'Quản lý phòng ban / chức vụ'],

            // Attendance
            ['code' => 'attendance.check', 'name' => 'Chấm công (check-in/out)'],
            ['code' => 'attendance.view_own', 'name' => 'Xem chấm công của bản thân'],
            ['code' => 'attendance.view_all', 'name' => 'Xem chấm công toàn bộ nhân viên'],
            ['code' => 'attendance.adjust', 'name' => 'Yêu cầu / duyệt điều chỉnh công'],

            // Leave
            ['code' => 'leave.request', 'name' => 'Tạo đơn xin nghỉ phép'],
            ['code' => 'leave.view_own', 'name' => 'Xem đơn nghỉ phép của bản thân'],
            ['code' => 'leave.view_all', 'name' => 'Xem đơn nghỉ phép toàn bộ nhân viên'],
            ['code' => 'leave.approve', 'name' => 'Duyệt đơn nghỉ phép'],

            // Payroll
            ['code' => 'payroll.view_own', 'name' => 'Xem phiếu lương của bản thân'],
            ['code' => 'payroll.view_all', 'name' => 'Xem bảng lương toàn công ty'],
            ['code' => 'payroll.manage', 'name' => 'Cấu hình & chốt bảng lương'],

            // Report
            ['code' => 'report.view', 'name' => 'Xem báo cáo / thống kê'],

            // RBAC & System
            ['code' => 'rbac.manage', 'name' => 'Quản lý vai trò & phân quyền'],
            ['code' => 'system.audit_log', 'name' => 'Xem nhật ký hệ thống (audit log)'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $permission['code'], 'guard_name' => 'api'],
                [
                    'name' => $permission['name'],
                    'guard_name' => 'api',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
