<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $leaveTypes = [
            ['code' => 'annual', 'name' => 'Nghỉ phép năm', 'annual_entitlement_days' => 12, 'is_paid' => true, 'allow_carry_forward' => true, 'max_carry_forward_days' => 5],
            ['code' => 'sick', 'name' => 'Nghỉ ốm', 'annual_entitlement_days' => 0, 'is_paid' => true, 'allow_carry_forward' => false, 'max_carry_forward_days' => null],
            ['code' => 'maternity', 'name' => 'Nghỉ thai sản', 'annual_entitlement_days' => 0, 'is_paid' => true, 'allow_carry_forward' => false, 'max_carry_forward_days' => null],
            ['code' => 'paternity', 'name' => 'Nghỉ chế độ cha/mẹ', 'annual_entitlement_days' => 0, 'is_paid' => true, 'allow_carry_forward' => false, 'max_carry_forward_days' => null],
            ['code' => 'unpaid', 'name' => 'Nghỉ không lương', 'annual_entitlement_days' => 0, 'is_paid' => false, 'allow_carry_forward' => false, 'max_carry_forward_days' => null],
        ];

        foreach ($leaveTypes as $leaveType) {
            DB::table('leave_types')->updateOrInsert(
                ['code' => $leaveType['code']],
                [
                    'name' => $leaveType['name'],
                    'annual_entitlement_days' => $leaveType['annual_entitlement_days'],
                    'is_paid' => $leaveType['is_paid'],
                    'allow_carry_forward' => $leaveType['allow_carry_forward'],
                    'max_carry_forward_days' => $leaveType['max_carry_forward_days'],
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
