<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Trang Nhật ký hoạt động (2026-10-15): lọc theo ngày / loại thao tác, gom các dòng của
// cùng 1 lần thao tác (request_id), và job logs:prune xóa log cũ theo created_at.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('created_at', 'audit_logs_created_at_index');
            $table->index(['action', 'created_at'], 'audit_logs_action_created_index');
            $table->index('request_id', 'audit_logs_request_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_created_at_index');
            $table->dropIndex('audit_logs_action_created_index');
            $table->dropIndex('audit_logs_request_id_index');
        });
    }
};
