<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'Admin', 'guard_name' => 'api', 'description' => 'Quản trị hệ thống - toàn quyền'],
            ['name' => 'HR', 'guard_name' => 'api', 'description' => 'Nhân sự - quản lý hồ sơ, chấm công, nghỉ phép, lương'],
            ['name' => 'Manager', 'guard_name' => 'api', 'description' => 'Trưởng phòng - duyệt nghỉ phép, xem báo cáo phòng ban'],
            ['name' => 'Employee', 'guard_name' => 'api', 'description' => 'Nhân viên - tự chấm công, xin nghỉ phép, xem phiếu lương'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name'], 'guard_name' => $role['guard_name']],
                array_merge($role, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
