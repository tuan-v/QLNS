<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Tài khoản ngân hàng nhận lương (Kế hoạch Ngày 21): nhân viên tự nhập, HR xác nhận
// mới được dùng làm tài khoản nhận lương. Xem EmployeeBankAccountService.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('employee_bank_accounts', function (Blueprint $table) {
            $table->string('bank_code', 20)->nullable()->after('employee_id');
            $table->string('status', 20)->default('pending')->index()->after('is_primary'); // pending | verified | rejected
            $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->text('review_note')->nullable()->after('verified_at');
            // Khóa ngoại employee_id đang dựa vào unique bên dưới — thêm index riêng trước khi bỏ.
            $table->index('employee_id');
        });

        // Unique (employee_id, account_number) chặn cả dòng đã xóa mềm -> thêm lại đúng số
        // cũ sẽ lỗi. Kiểm tra trùng chuyển sang Service (chỉ tính dòng chưa xóa).
        Schema::table('employee_bank_accounts', function (Blueprint $table) {
            $table->dropUnique(['employee_id', 'account_number']);
        });

        // Dữ liệu cũ (nếu có) coi như đã được HR xác nhận.
        DB::table('employee_bank_accounts')->update(['status' => 'verified']);

        // Việc "Hoàn thiện hồ sơ cá nhân" của mẫu nhận việc mặc định tự đánh dấu khi đã
        // có tài khoản nhận lương được xác nhận.
        $templateIds = DB::table('checklist_templates')->where('type', 'onboarding')->where('is_default', true)->pluck('id');
        DB::table('checklist_template_items')
            ->whereIn('checklist_template_id', $templateIds)
            ->where('title', 'Hoàn thiện hồ sơ cá nhân')
            ->whereNull('auto_key')
            ->update(['auto_key' => 'bank_account_verified', 'description' => 'CCCD, ảnh đại diện và tài khoản ngân hàng nhận lương (tự đánh dấu khi HR xác nhận tài khoản).']);
    }

    public function down(): void
    {
        DB::table('checklist_template_items')->where('auto_key', 'bank_account_verified')->update(['auto_key' => null]);

        Schema::table('employee_bank_accounts', function (Blueprint $table) {
            $table->unique(['employee_id', 'account_number']);
        });

        Schema::table('employee_bank_accounts', function (Blueprint $table) {
            $table->dropIndex(['employee_id']);
            $table->dropConstrainedForeignId('verified_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['bank_code', 'status', 'verified_at', 'review_note']);
        });
    }
};
