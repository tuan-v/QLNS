<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mở rộng attendance_adjustments hỗ trợ thêm loại "supplement" (bổ sung
// chấm công cho ca/ngày hoàn toàn CHƯA có bản ghi — khác "correction" hiện
// có, chỉ sửa bản ghi ĐÃ TỒN TẠI). employee_id/work_shift_id/attendance_date
// được set cho CẢ 2 loại (không chỉ supplement) — với correction thì copy
// từ Attendance lúc tạo, nhờ vậy giao diện/API đọc luôn thẳng trên chính
// adjustment, không cần phân nhánh theo type.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('attendance_adjustments', function (Blueprint $table) {
            $table->string('type', 20)->default('correction')->after('id');
            $table->foreignId('employee_id')->after('type')->constrained('employees')->restrictOnDelete();
            $table->foreignId('work_shift_id')->nullable()->after('attendance_id')->constrained('work_shifts')->restrictOnDelete();
            $table->date('attendance_date')->nullable()->after('work_shift_id');
        });

        // Blueprint::change() có sẵn native cho cả MySQL/SQLite từ Laravel
        // 11 (không cần doctrine/dbal nữa, dự án không cài package đó) — bộ
        // test chạy trên SQLite in-memory nên không dùng raw "ALTER ...
        // MODIFY" (cú pháp riêng MySQL, SQLite không hiểu).
        Schema::table('attendance_adjustments', function (Blueprint $table) {
            $table->unsignedBigInteger('attendance_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_adjustments', function (Blueprint $table) {
            $table->unsignedBigInteger('attendance_id')->nullable(false)->change();
        });

        Schema::table('attendance_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropConstrainedForeignId('work_shift_id');
            $table->dropColumn(['type', 'attendance_date']);
        });
    }
};
