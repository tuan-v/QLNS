<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Ca mặc định" (2026-09-23, theo yêu cầu người dùng): vẫn giữ khả năng tạo
// NHIỀU ca khác nhau (mục 12) cho trường hợp đặc biệt (ca đêm, part-time...),
// nhưng có ĐÚNG 1 ca được đánh dấu `is_default=true` — nhân viên MỚI tạo tự
// động được gán ca này (xem EmployeeShiftAssignmentService::assignDefaultShift()),
// và trang "Cài đặt" chỉ sửa NGAY trên bản ghi này nên đổi giờ ở đó tự động
// áp dụng cho MỌI nhân viên đang gán ca mặc định — không cần code lan truyền
// riêng, vì mọi nhân viên cùng trỏ chung 1 work_shift_id.
//
// Đổi `break_minutes` (chỉ 1 con số, KHÔNG dùng để tính toán ở đâu cả — xem
// WorkShiftService) sang `break_start_time`/`break_end_time` (giờ nghỉ trưa
// TỪ - ĐẾN rõ ràng) — giờ THẬT SỰ được trừ vào actual_work_minutes nếu
// khoảng chấm công VÀO-RA của nhân viên trùng vào giờ nghỉ trưa này (xem
// AttendanceService::calculateActualWorkMinutes()). Không tự quy đổi dữ liệu
// break_minutes cũ sang break_start/break_end vì chỉ có SỐ PHÚT, không biết
// nghỉ trưa bắt đầu lúc nào — bản ghi cũ coi như chưa khai báo (NULL, không
// trừ gì, giữ nguyên hành vi cũ).
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
            $table->time('break_start_time')->nullable()->after('break_minutes');
            $table->time('break_end_time')->nullable()->after('break_start_time');
        });

        Schema::table('work_shifts', function (Blueprint $table) {
            $table->dropColumn('break_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->smallInteger('break_minutes')->default(0)->after('is_default');
        });

        Schema::table('work_shifts', function (Blueprint $table) {
            $table->dropColumn(['is_default', 'break_start_time', 'break_end_time']);
        });
    }
};
