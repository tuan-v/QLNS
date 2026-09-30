<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Đơn nghỉ việc không phải đơn nào cũng cần duyệt (2026-09-30, theo yêu cầu
// người dùng, theo BLLĐ 2019 Điều 35): báo trước ĐỦ ngày thì chỉ là "thông báo"
// (status 'notified'), thiếu ngày mới phải chờ duyệt.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('resignation_requests', function (Blueprint $table) {
            // Số ngày báo trước tối thiểu theo hợp đồng lúc nộp, và số ngày
            // nhân viên thực tế báo (ngày làm việc cuối - ngày nộp) — lưu lại
            // để đơn cũ không đổi nghĩa khi luật/hợp đồng thay đổi.
            $table->unsignedSmallInteger('notice_days_required')->nullable()->after('reason');
            $table->unsignedSmallInteger('notice_days_given')->nullable()->after('notice_days_required');
            $table->boolean('requires_approval')->default(true)->after('notice_days_given');
        });
    }

    public function down(): void
    {
        Schema::table('resignation_requests', function (Blueprint $table) {
            $table->dropColumn(['notice_days_required', 'notice_days_given', 'requires_approval']);
        });
    }
};
