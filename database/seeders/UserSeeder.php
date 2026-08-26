<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'email' => 'admin@qlns.local',
                'user_name' => 'Quản trị hệ thống',
                'password' => 'Admin@123',
                'status' => 'active',
            ],
            [
                'email' => 'hr@qlns.local',
                'user_name' => 'Nhân sự',
                'password' => 'Hr@123456',
                'status' => 'active',
            ],
            [
                'email' => 'manager@qlns.local',
                'user_name' => 'Trưởng phòng',
                'password' => 'Manager@123',
                'status' => 'active',
            ],
            [
                'email' => 'employee@qlns.local',
                'user_name' => 'Nhân viên',
                'password' => 'Employee@123',
                'status' => 'active',
            ],
        ];

        foreach ($users as $user) {
            DB::table('users')->updateOrInsert(
                ['email' => $user['email']],
                [
                    'user_name' => $user['user_name'],
                    'password' => Hash::make($user['password']),
                    'status' => $user['status'],
                    'email_verified_at' => now(),
                    'password_changed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
