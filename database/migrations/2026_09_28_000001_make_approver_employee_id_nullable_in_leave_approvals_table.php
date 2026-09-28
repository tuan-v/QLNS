<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('leave_approvals', function (Blueprint $table) {
            $table->foreignId('approver_employee_id')->nullable()->change();
            $table->foreignId('approver_user_id')->nullable()->after('approver_employee_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_approvals', function (Blueprint $table) {
            $table->dropForeign(['approver_user_id']);
            $table->dropColumn('approver_user_id');
            $table->foreignId('approver_employee_id')->nullable(false)->change();
        });
    }
};
