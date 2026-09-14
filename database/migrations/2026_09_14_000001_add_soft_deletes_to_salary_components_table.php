<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    // Đồng bộ hóa: các bảng cấu hình khác (positions, work_shifts...) đều có
    // sẵn softDeletes() từ lúc dựng migration ban đầu — bảng này bị bỏ sót vì
    // Payroll chưa được xây lúc đó (chỉ có migration, chưa có Model/Service).
    public function up(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
