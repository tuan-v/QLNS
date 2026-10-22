<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lý do tăng/giảm lương của hợp đồng so với hợp đồng trước (2026-10-22, cho lộ trình nhân viên). Bắt buộc khi
// lương khác hợp đồng trước (EmployeeContractService::create()); hợp đồng cũ bổ sung sau qua fillMissing().
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->string('salary_change_note', 500)->nullable()->after('insurance_salary');
        });
    }

    public function down(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->dropColumn('salary_change_note');
        });
    }
};
