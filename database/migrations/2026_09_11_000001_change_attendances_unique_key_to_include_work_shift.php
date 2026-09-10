<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 1 nhân viên có thể có nhiều ca cùng ngày (mục 14, vd Ca sáng + Ca chiều) —
// đổi từ 1 dòng/nhân viên/ngày sang 1 dòng/ca/ngày để chấm công độc lập
// theo từng ca. An toàn với dữ liệu cũ: bộ cột mới là superset của unique
// cũ nên không thể vi phạm ràng buộc.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // unique(employee_id, attendance_date) hiện là index DUY NHẤT
            // đỡ luôn cho FK employee_id (không có index đơn riêng, khác
            // work_shift_id đã có attendances_work_shift_id_foreign) — MySQL
            // từ chối xóa nó (lỗi 1553) nếu không có index thay thế cho FK
            // TRƯỚC. Thêm index đơn cho employee_id trước khi đổi unique.
            $table->index('employee_id', 'attendances_employee_id_index');
            $table->dropUnique(['employee_id', 'attendance_date']);
            $table->unique(['employee_id', 'attendance_date', 'work_shift_id']);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'attendance_date', 'work_shift_id']);
            $table->unique(['employee_id', 'attendance_date']);
            $table->dropIndex('attendances_employee_id_index');
        });
    }
};
