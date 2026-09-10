<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    // Đồng bộ với work_shifts/attendance_locations/employee_shift_assignments
    // — mọi bảng trong chuỗi Chấm công đều xóa mềm (yêu cầu chung của dự án).
    // KHÔNG áp dụng cho attendance_logs — bảng đó là nhật ký sự kiện thô
    // (mỗi lần quẹt Wifi/GPS/QR), bất biến giống EmployeeTransfer, không có
    // khái niệm "xóa mềm rồi khôi phục" (xem CODE_MAP mục 8, mục Luân chuyển).
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
