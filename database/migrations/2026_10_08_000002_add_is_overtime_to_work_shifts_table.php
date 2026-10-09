<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ca OT 1 ngày (tạo khi HR duyệt đơn "OT ngày khác"): toàn bộ giờ làm trong khung
// ca là OT, không cộng công ngày; ẩn khỏi danh sách ca thường.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->boolean('is_overtime')->default(false)->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->dropColumn('is_overtime');
        });
    }
};
