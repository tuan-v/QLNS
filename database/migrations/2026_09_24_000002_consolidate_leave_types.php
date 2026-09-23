<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Gộp danh mục loại phép còn 3 nhóm (2026-09-24, theo yêu cầu người dùng:
// "bỏ những cái như thai sản gì đi chỉ có nghỉ phép có lương và nghỉ không
// lương hoặc nghỉ khác theo chế độ hay luật") — xem comment đầu
// LeaveTypeSeeder.php. Xóa THẬT 3 dòng cũ 'sick'/'maternity'/'paternity'
// (không chỉ tắt is_active) vì đã kiểm tra trên DB dev: KHÔNG có
// leave_requests/leave_balances nào tham chiếu tới — an toàn xóa hẳn. Nếu
// môi trường nào đó LỠ đã có dữ liệu tham chiếu, FK `restrictOnDelete()`
// (xem create_leave_balances_table/create_leave_requests_table) sẽ tự chặn
// migration này lại thay vì âm thầm làm mất dữ liệu — cần xử lý thủ công
// dữ liệu đó trước khi chạy lại.
return new class () extends Migration {
    public function up(): void
    {
        DB::table('leave_types')->updateOrInsert(
            ['code' => 'other'],
            [
                'name' => 'Nghỉ khác theo chế độ/luật',
                'annual_entitlement_days' => 0,
                'is_paid' => true,
                'allow_carry_forward' => false,
                'max_carry_forward_days' => null,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('leave_types')->whereIn('code', ['sick', 'maternity', 'paternity'])->delete();
    }

    public function down(): void
    {
        DB::table('leave_types')->whereIn('code', ['sick', 'maternity', 'paternity'])->delete();

        foreach ([
            ['code' => 'sick', 'name' => 'Nghỉ ốm'],
            ['code' => 'maternity', 'name' => 'Nghỉ thai sản'],
            ['code' => 'paternity', 'name' => 'Nghỉ chế độ cha/mẹ'],
        ] as $leaveType) {
            DB::table('leave_types')->updateOrInsert(
                ['code' => $leaveType['code']],
                [
                    'name' => $leaveType['name'],
                    'annual_entitlement_days' => 0,
                    'is_paid' => true,
                    'allow_carry_forward' => false,
                    'max_carry_forward_days' => null,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        DB::table('leave_types')->where('code', 'other')->delete();
    }
};
