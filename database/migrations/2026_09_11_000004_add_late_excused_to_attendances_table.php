<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // true khi HR duyệt 1 yêu cầu "Xin miễn trừ đi muộn" (mục 17,
            // AttendanceAdjustment type='excuse', Ngày 42) — late_minutes GIỮ
            // NGUYÊN (không sửa giờ vào thật), chỉ không tính vào thống kê
            // đi muộn/nhãn "Đi muộn" ở Lịch sử chấm công (mục 18).
            $table->boolean('late_excused')->default(false)->after('late_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('late_excused');
        });
    }
};
