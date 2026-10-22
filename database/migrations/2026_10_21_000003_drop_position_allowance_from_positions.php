<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bỏ "Phụ cấp chức vụ": trường này chưa từng được cộng vào lương, phụ cấp giờ quản lý riêng ở trang Phụ cấp
// (salary_components + employee_allowances).
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('position_allowance');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->decimal('position_allowance', 18, 2)->nullable()->after('level');
        });
    }
};
