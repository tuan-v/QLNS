<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // "level" = cấp bậc, quyết định ai được gán vai trò nào cho người khác:
        // chỉ gán được vai trò cấp THẤP HƠN mình (xem RoleService::assertCanAssign()).
        // Giá trị phải nằm trên thang Role::LEVELS (0/20/40/60/80/100) — bậc 80
        // để trống sẵn cho cấp trung gian trên HR nếu sau này cần.
        $roles = [
            ['name' => 'Admin', 'guard_name' => 'api', 'level' => 100, 'description' => 'Quản trị hệ thống - toàn quyền'],
            ['name' => 'HR', 'guard_name' => 'api', 'level' => 60, 'description' => 'Nhân sự - quản lý hồ sơ, chấm công, nghỉ phép, lương'],
            ['name' => 'Manager', 'guard_name' => 'api', 'level' => 40, 'description' => 'Trưởng phòng - duyệt nghỉ phép, xem báo cáo phòng ban'],
            ['name' => 'Employee', 'guard_name' => 'api', 'level' => 20, 'description' => 'Nhân viên - tự chấm công, xin nghỉ phép, xem phiếu lương'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name'], 'guard_name' => $role['guard_name']],
                array_merge($role, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
