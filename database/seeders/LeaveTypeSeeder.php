<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// Danh mục 3 loại phép (2026-09-24, theo yêu cầu người dùng — trước đó có
// 5 loại: annual/sick/maternity/paternity/unpaid, người dùng yêu cầu bỏ các
// loại chi tiết theo tình huống (ốm/thai sản/chế độ cha-mẹ), gộp chung vào
// đúng 3 nhóm: có lương (annual — vẫn tích lũy theo tháng/thâm niên, mục
// 29), không lương (unpaid), và "khác theo chế độ/luật" (other — GỘP
// sick+maternity+paternity cũ, vẫn bắt buộc đính kèm giấy tờ — xem
// StoreLeaveRequest::withValidator()). Dữ liệu CŨ ('sick'/'maternity'/
// 'paternity') được dọn ở migration
// 2026_09_24_000002_consolidate_leave_types.php — file này chỉ định nghĩa
// trạng thái ĐÍCH cho môi trường mới/test (RefreshDatabase + seed()).
class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $leaveTypes = [
            ['code' => 'annual', 'name' => 'Nghỉ phép năm', 'annual_entitlement_days' => 12, 'is_paid' => true, 'allow_carry_forward' => true, 'max_carry_forward_days' => 5],
            ['code' => 'other', 'name' => 'Nghỉ khác theo chế độ/luật', 'annual_entitlement_days' => 0, 'is_paid' => true, 'allow_carry_forward' => false, 'max_carry_forward_days' => null],
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
