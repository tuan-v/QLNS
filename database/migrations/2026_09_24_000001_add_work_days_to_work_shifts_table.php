<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Ngày làm việc mặc định" (2026-09-24, theo yêu cầu người dùng: trang Cài
// đặt cần chọn được ngày làm việc trong tuần, không chỉ giờ vào-ra) — trước
// giờ T2-T6 bị hard-code ở EmployeeShiftAssignmentService::DEFAULT_WORK_DAYS,
// không có chỗ nào chỉnh được. Thêm cột JSON này vào work_shifts (không phải
// employee_shift_assignments) để nhất quán với cách "Ca mặc định" đã hoạt
// động: sửa NGAY trên 1 dòng WorkShift duy nhất (is_default=true) thì áp
// dụng cho MỌI nhân viên đang theo ca đó — xem
// WorkShiftService::update()/EmployeeShiftAssignmentService::resyncOpenEndedWorkDays().
// Chỉ CA MẶC ĐỊNH thật sự dùng cột này (lúc assignDefaultShift() tạo bản gán
// mới); các Ca khác/bản gán tay ở tab "Ca làm việc" (mục 14) vẫn tự chọn
// work_days riêng của EmployeeShiftAssignment như cũ, không đọc cột này.
// NULL (ca tạo trước migration này, hoặc chưa từng chỉnh ở Cài đặt) = coi
// như chưa cấu hình, dùng T2-T6 làm phương án dự phòng (xem
// EmployeeShiftAssignmentService::DEFAULT_WORK_DAYS).
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->json('work_days')->nullable()->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->dropColumn('work_days');
        });
    }
};
