<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // Chỉ dùng khi start_session/end_session = 'hourly' — nghỉ vài
            // tiếng trong CÙNG 1 ngày (from_date = to_date), không dùng cho
            // nghỉ nhiều ngày. Xem LeaveRequestService::calculateTotalDays().
            $table->time('start_time')->nullable()->after('end_session');
            $table->time('end_time')->nullable()->after('start_time');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time']);
        });
    }
};
