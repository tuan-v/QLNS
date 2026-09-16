<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Số tiền OT (đã tính, nhân hệ số 150%) trước đây bị cộng thẳng vào
// gross_salary rồi bỏ đi, không lưu riêng — Frontend không có cách nào hiện
// lại đúng số tiền OT bằng VNĐ (chỉ có overtime_minutes là số phút).
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->decimal('overtime_amount', 18, 2)->default(0)->after('overtime_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->dropColumn('overtime_amount');
        });
    }
};
