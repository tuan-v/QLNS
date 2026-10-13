<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Tuyển dụng chung" (người dùng duyệt 2026-10-13): đợt tuyển KHÔNG chọn phòng ban/chức vụ,
// không giới hạn số lượng; mỗi ứng viên ghi vị trí ứng tuyển (chức vụ có sẵn) + hình thức
// mong muốn; bộ câu hỏi theo từng ứng viên; phòng ban/chức vụ chốt trong thư mời (offer).
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('recruitment_openings', function (Blueprint $table) {
            $table->unsignedBigInteger('department_id')->nullable()->change(); // null = tuyển dụng chung
            $table->unsignedSmallInteger('headcount')->nullable()->change();   // null = không giới hạn
        });

        Schema::table('recruitment_candidates', function (Blueprint $table) {
            $table->foreignId('applied_position_id')->nullable()->after('phone')
                ->constrained('positions', 'id', 'rc_applied_position_fk')->nullOnDelete();
            $table->string('desired_contract_type', 20)->nullable()->after('applied_position_id'); // thu_viec|chinh_thuc|thuc_tap
        });

        // Bộ câu hỏi riêng của 1 ứng viên (Admin chọn) — ưu tiên hơn bộ của chức vụ/đợt tuyển.
        Schema::create('recruitment_candidate_question_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_candidate_id')->constrained('recruitment_candidates', 'id', 'rcqs_candidate_fk')->cascadeOnDelete();
            $table->foreignId('interview_question_set_id')->constrained('interview_question_sets', 'id', 'rcqs_set_fk')->cascadeOnDelete();
            $table->unsignedTinyInteger('weight');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['recruitment_candidate_id', 'interview_question_set_id'], 'rcqs_candidate_set_unique');
        });

        Schema::table('recruitment_offers', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('recruitment_candidate_id')
                ->constrained('departments', 'id', 'ro_department_fk')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->after('department_id')
                ->constrained('positions', 'id', 'ro_position_fk')->nullOnDelete();
        });

        // Offer cũ: phòng ban/chức vụ = của đợt tuyển (như cách nhận việc đang điền sẵn).
        DB::table('recruitment_offers')->orderBy('id')->each(function ($offer) {
            $opening = DB::table('recruitment_candidates as c')
                ->join('recruitment_openings as o', 'o.id', '=', 'c.recruitment_opening_id')
                ->where('c.id', $offer->recruitment_candidate_id)
                ->first(['o.department_id', 'o.position_id']);
            if ($opening) {
                DB::table('recruitment_offers')->where('id', $offer->id)
                    ->update(['department_id' => $opening->department_id, 'position_id' => $opening->position_id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_offers', function (Blueprint $table) {
            $table->dropForeign('ro_position_fk');
            $table->dropForeign('ro_department_fk');
            $table->dropColumn(['department_id', 'position_id']);
        });
        Schema::dropIfExists('recruitment_candidate_question_sets');
        Schema::table('recruitment_candidates', function (Blueprint $table) {
            $table->dropForeign('rc_applied_position_fk');
            $table->dropColumn(['applied_position_id', 'desired_contract_type']);
        });
        // Đợt tuyển chung không thể quay về NOT NULL nếu đã có — giữ nullable khi rollback.
    }
};
