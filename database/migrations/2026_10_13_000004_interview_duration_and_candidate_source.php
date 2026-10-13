<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// (1) Chặn trùng lịch phỏng vấn của cùng 1 người phỏng vấn: cần biết buổi PV kéo dài bao
//     lâu -> duration_minutes (mặc định 60; hẹn hàng loạt = thời lượng mỗi lượt).
// (2) Báo cáo tuyển dụng theo kênh: nguồn ứng viên (TopCV, VietnamWorks, giới thiệu…)
//     + ghi chú nguồn (vd tên người giới thiệu). Người dùng duyệt 2026-10-13.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('recruitment_interviews', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')->default(60)->after('scheduled_at');
        });

        Schema::table('recruitment_candidates', function (Blueprint $table) {
            $table->string('source', 30)->nullable()->after('desired_contract_type');
            $table->string('source_note', 255)->nullable()->after('source');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_candidates', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'source_note']);
        });
        Schema::table('recruitment_interviews', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
    }
};
