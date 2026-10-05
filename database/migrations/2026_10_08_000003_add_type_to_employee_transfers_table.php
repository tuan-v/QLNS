<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Lịch sử luân chuyển đầy đủ: thêm loại sự kiện — 'onboard' (tiếp nhận: phòng ban +
// chức vụ đầu tiên), 'transfer' (điều chuyển), 'adjustment' (đổi phòng ban/chức vụ
// ngoài màn điều chuyển: sửa hồ sơ, bổ nhiệm/thôi Trưởng phòng).
// Ghi bù 1 dòng 'onboard' cho nhân viên đang có: phòng ban/chức vụ ban đầu lấy từ
// lần điều chuyển đầu tiên (nếu có), không thì lấy hiện tại; ngày = ngày vào làm.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('employee_transfers', function (Blueprint $table) {
            $table->string('type', 20)->default('transfer')->after('employee_id');
        });

        $employees = DB::table('employees')->get(['id', 'department_id', 'position_id', 'hire_date', 'created_at']);

        foreach ($employees as $employee) {
            $first = DB::table('employee_transfers')
                ->where('employee_id', $employee->id)
                ->orderBy('effective_date')
                ->orderBy('id')
                ->first();

            $departmentId = $first ? ($first->from_department_id ?? $employee->department_id) : $employee->department_id;
            $positionId = $first ? $first->old_position_id : $employee->position_id;

            if ($departmentId === null) {
                continue;
            }

            DB::table('employee_transfers')->insert([
                'employee_id' => $employee->id,
                'type' => 'onboard',
                'from_department_id' => null,
                'to_department_id' => $departmentId,
                'old_position_id' => null,
                'new_position_id' => $positionId,
                'effective_date' => $employee->hire_date ?? substr((string) $employee->created_at, 0, 10),
                'reason' => 'Tiếp nhận nhân viên (ghi bù từ dữ liệu cũ)',
                'approved_at' => $employee->created_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('employee_transfers')->where('type', '!=', 'transfer')->delete();

        Schema::table('employee_transfers', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
