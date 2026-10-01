<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cờ "đã được duyệt xin về sớm" — cùng cơ chế late_excused (miễn trừ đi muộn):
// early_leave_minutes GIỮ NGUYÊN số phút thực, chỉ cờ này quyết định có bị trừ
// công hay không (AttendanceAdjustment type='early_leave', xem
// AttendanceAdjustmentService::decide() và WorkTimeCalculationService).
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->boolean('early_leave_excused')->default(false)->after('early_leave_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropColumn('early_leave_excused');
        });
    }
};
