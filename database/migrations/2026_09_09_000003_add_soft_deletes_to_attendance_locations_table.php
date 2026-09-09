<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    // Đồng bộ hóa: mọi bảng trong chuỗi Ca làm việc/Chấm công đều xóa mềm
    // (work_shifts, employee_shift_assignments đã có sẵn softDeletes() từ
    // Ngày 04) — bảng này bị bỏ sót lúc dựng migration ban đầu.
    public function up(): void
    {
        Schema::table('attendance_locations', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_locations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
