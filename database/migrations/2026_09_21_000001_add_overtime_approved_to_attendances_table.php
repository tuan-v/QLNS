<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// OT chỉ được TRẢ LƯƠNG sau khi HR duyệt (xem AttendanceAdjustmentService,
// type "overtime" — cùng cơ chế "excuse" đã có cho miễn trừ đi muộn) —
// overtime_minutes vẫn luôn lưu đúng số THỰC (đã trừ ân hạn), cột này chỉ
// đánh dấu đã được duyệt hay chưa, PayrollService mới là nơi quyết định có
// trả lương hay không dựa vào cả cờ này lẫn ngưỡng phút tối thiểu.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->boolean('overtime_approved')->default(false)->after('overtime_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('overtime_approved');
        });
    }
};
