<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Ngày Văn hóa Việt Nam 24/11: nghỉ 1 ngày hưởng nguyên lương, Quốc hội thông qua
// 24/4/2026, hiệu lực từ 1/7/2026 (năm 2026 không hoán đổi ngày làm việc).
// Thêm cột effective_from để quy tắc mới chỉ áp dụng từ ngày có hiệu lực —
// không sinh ngày nghỉ cho các năm trước 2026.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('holiday_rules', function (Blueprint $table) {
            $table->date('effective_from')->nullable()->after('is_active');
        });

        $now = now();
        DB::table('holiday_rules')->insert([
            'name' => 'Ngày Văn hóa Việt Nam',
            'calendar' => 'solar',
            'month' => 11,
            'day' => 24,
            'offset_days' => 0,
            'duration_days' => 1,
            'compensate_weekend' => true,
            'is_paid' => true,
            'notify_days_before' => 7,
            'is_system' => true,
            'is_active' => true,
            'effective_from' => '2026-07-01',
            'description' => 'Ngày 24 tháng 11 dương lịch — nghỉ hưởng nguyên lương, áp dụng từ 1/7/2026.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('holiday_rules')->where('name', 'Ngày Văn hóa Việt Nam')->where('is_system', true)->delete();

        Schema::table('holiday_rules', function (Blueprint $table) {
            $table->dropColumn('effective_from');
        });
    }
};
