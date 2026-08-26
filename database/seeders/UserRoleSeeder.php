<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserRoleSeeder extends Seeder
{
    public function run(): void
    {
        $map = [
            'admin@qlns.local' => 'Admin',
            'hr@qlns.local' => 'HR',
            'manager@qlns.local' => 'Manager',
            'employee@qlns.local' => 'Employee',
        ];

        $userIds = DB::table('users')->pluck('id', 'email');
        $roleIds = DB::table('roles')->pluck('id', 'name');

        $adminId = $userIds['admin@qlns.local'] ?? null;

        foreach ($map as $email => $roleName) {
            DB::table('user_roles')->updateOrInsert(
                ['user_id' => $userIds[$email], 'role_id' => $roleIds[$roleName]],
                ['assigned_by' => $adminId, 'assigned_at' => now()]
            );
        }
    }
}
