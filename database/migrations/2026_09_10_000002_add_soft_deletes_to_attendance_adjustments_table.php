<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    // Đồng bộ với work_shifts/attendance_locations/employee_shift_assignments/
    // attendances — mọi bảng cấu hình/trạng thái trong chuỗi Chấm công đều xóa
    // mềm. Khác attendance_logs (nhật ký thô, bất biến) — 1 yêu cầu điều chỉnh
    // đang "pending" hoàn toàn có thể bị người tạo tự hủy, hoặc HR gỡ nhầm.
    public function up(): void
    {
        Schema::table('attendance_adjustments', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_adjustments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
