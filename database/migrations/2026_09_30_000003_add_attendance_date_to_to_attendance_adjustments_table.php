<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Xin làm ngoài lịch" cho NHIỀU ngày liền nhau (2026-09-30, theo yêu cầu người
// dùng): 1 đơn phủ khoảng [attendance_date, attendance_date_to]. NULL = chỉ 1 ngày.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('attendance_adjustments', function (Blueprint $table) {
            $table->date('attendance_date_to')->nullable()->after('attendance_date');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_adjustments', function (Blueprint $table) {
            $table->dropColumn('attendance_date_to');
        });
    }
};
