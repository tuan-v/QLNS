<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Thực tập sinh (hợp đồng 'thuc_tap') có được hưởng lương ngày nghỉ lễ hay không —
// chọn theo từng quy tắc/dịp nghỉ. Mặc định có; theo quy định công ty hiện tại
// Quốc khánh 2/9 thì KHÔNG (đặt sẵn cho quy tắc + mọi ngày nghỉ của quy tắc đó).
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('holiday_rules', function (Blueprint $table) {
            $table->boolean('intern_paid')->default(true)->after('compensate_weekend');
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->boolean('intern_paid')->default(true)->after('is_paid');
        });

        $nationalDayRuleIds = DB::table('holiday_rules')->where('name', 'Quốc khánh')->pluck('id');
        DB::table('holiday_rules')->whereIn('id', $nationalDayRuleIds)->update(['intern_paid' => false]);
        DB::table('holidays')->whereIn('holiday_rule_id', $nationalDayRuleIds)->update(['intern_paid' => false]);
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn('intern_paid');
        });

        Schema::table('holiday_rules', function (Blueprint $table) {
            $table->dropColumn('intern_paid');
        });
    }
};
