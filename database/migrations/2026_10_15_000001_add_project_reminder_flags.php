<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cờ "đã nhắc" cho các lời nhắc hằng ngày của module Dự án (job projects:remind-tasks) —
// mỗi mốc chỉ nhắc 1 lần; đổi ngày (hạn, ngày bắt đầu, ngày kết thúc) thì xóa cờ để nhắc lại.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->timestamp('due_today_notified_at')->nullable()->after('due_soon_notified_at');
            $table->timestamp('start_notified_at')->nullable()->after('due_today_notified_at');
            $table->timestamp('review_reminded_at')->nullable()->after('overdue_notified_at');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('start_reminded_at')->nullable()->after('status');
            $table->timestamp('end_soon_notified_at')->nullable()->after('start_reminded_at');
            $table->timestamp('overdue_notified_at')->nullable()->after('end_soon_notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropColumn(['due_today_notified_at', 'start_notified_at', 'review_reminded_at']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['start_reminded_at', 'end_soon_notified_at', 'overdue_notified_at']);
        });
    }
};
