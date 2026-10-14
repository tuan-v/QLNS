<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lúc người làm bấm "Bắt đầu" — hiển thị trên dòng thời gian hoạt động của công việc.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropColumn('started_at');
        });
    }
};
