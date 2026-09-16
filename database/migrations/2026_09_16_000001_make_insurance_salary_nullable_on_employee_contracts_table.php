<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hợp đồng "thử việc" (contract_type=thu_viec) không bắt buộc đóng bảo hiểm
// (validation ở StoreEmployeeContractRequest đã đổi insurance_salary thành
// nullable/required_if:contract_type,chinh_thuc) — nhưng cột DB gốc (migration
// Ngày 24) chưa từng nullable, khiến insert NULL vỡ SQLSTATE[23000] "Column
// 'insurance_salary' cannot be null". Phát hiện qua kiểm thử thật (Playwright),
// không phải suy đoán.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->decimal('insurance_salary', 18, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->decimal('insurance_salary', 18, 2)->nullable(false)->change();
        });
    }
};
