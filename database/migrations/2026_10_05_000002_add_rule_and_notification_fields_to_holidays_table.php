<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mỗi dòng `holidays` vẫn là MỘT ngày nghỉ cụ thể (PayrollService đọc để trừ ngày
// công chuẩn). Thêm:
//   - group_code: các ngày của cùng một dịp (Tết 5 ngày + ngày nghỉ bù) chung 1 mã,
//     để giao diện hiển thị theo dịp và thông báo gửi 1 lần cho cả dịp.
//   - holiday_rule_id / source: sinh từ quy tắc (rule), nghỉ bù (compensatory) hay HR tự thêm (manual).
//   - notified_at: đã gửi thông báo nghỉ lễ cho nhân viên chưa (chống gửi lặp).
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->foreignId('holiday_rule_id')->nullable()->after('id')->constrained('holiday_rules')->nullOnDelete();
            $table->string('group_code', 64)->nullable()->after('holiday_rule_id')->index();
            $table->string('source', 20)->default('manual')->after('group_code');
            $table->string('note', 500)->nullable()->after('work_coefficient');
            $table->timestamp('notified_at')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('holiday_rule_id');
            $table->dropColumn(['group_code', 'source', 'note', 'notified_at']);
        });
    }
};
