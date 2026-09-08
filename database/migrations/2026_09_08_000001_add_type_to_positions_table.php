<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            // null = chức vụ tự đặt tay (Senior Dev, Sales Exec...).
            // 'head' = "Trưởng phòng" hệ thống tự sinh, tối đa 1 bản ghi/phòng ban.
            // 'default' = "Nhân viên" hệ thống tự sinh, nơi hạ chức Trưởng phòng cũ về.
            $table->string('type', 20)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
