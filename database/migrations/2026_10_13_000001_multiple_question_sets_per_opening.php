<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Nhiều bộ câu hỏi cho 1 đợt tuyển + bộ câu hỏi mặc định theo chức vụ (người dùng duyệt
// 2026-10-13). Mỗi bộ trong đợt có TỈ TRỌNG (%, tổng 100) — vd "Văn hóa chung" 30% +
// "Chuyên môn" 70%. Phiếu chấm chia theo phần (mỗi bộ 1 phần). Dữ liệu cũ: đợt đang gắn 1 bộ
// -> chuyển sang bảng nối với tỉ trọng 100%, rồi bỏ cột recruitment_openings.interview_question_set_id.
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('recruitment_opening_question_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_opening_id')->constrained('recruitment_openings', 'id', 'roqs_opening_fk')->cascadeOnDelete();
            $table->foreignId('interview_question_set_id')->constrained('interview_question_sets', 'id', 'roqs_set_fk')->cascadeOnDelete();
            $table->unsignedTinyInteger('weight'); // % tỉ trọng, tổng các bộ của 1 đợt = 100
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['recruitment_opening_id', 'interview_question_set_id'], 'roqs_opening_set_unique');
        });

        Schema::create('position_question_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained('positions', 'id', 'pqs_position_fk')->cascadeOnDelete();
            $table->foreignId('interview_question_set_id')->constrained('interview_question_sets', 'id', 'pqs_set_fk')->cascadeOnDelete();
            $table->unsignedTinyInteger('weight');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['position_id', 'interview_question_set_id'], 'pqs_position_set_unique');
        });

        // Phiếu chấm: các phần (bản chụp bộ câu hỏi + tỉ trọng + % đạt lúc mở phiếu); mỗi câu thuộc 1 phần.
        Schema::table('interview_scorecards', function (Blueprint $table) {
            $table->json('sections')->nullable()->after('pass_percent');
        });
        Schema::table('interview_scorecard_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('section_index')->default(0)->after('interview_scorecard_id');
        });

        $now = now();
        DB::table('recruitment_openings')->whereNotNull('interview_question_set_id')->orderBy('id')
            ->each(function ($opening) use ($now) {
                DB::table('recruitment_opening_question_sets')->insert([
                    'recruitment_opening_id' => $opening->id,
                    'interview_question_set_id' => $opening->interview_question_set_id,
                    'weight' => 100,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        Schema::table('recruitment_openings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('interview_question_set_id');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_openings', function (Blueprint $table) {
            $table->foreignId('interview_question_set_id')->nullable()->after('position_id')
                ->constrained('interview_question_sets')->nullOnDelete();
        });

        // Quay lại 1 bộ/đợt: giữ bộ có tỉ trọng lớn nhất.
        DB::table('recruitment_opening_question_sets')->orderByDesc('weight')->orderBy('sort_order')->get()
            ->unique('recruitment_opening_id')
            ->each(fn ($row) => DB::table('recruitment_openings')->where('id', $row->recruitment_opening_id)
                ->update(['interview_question_set_id' => $row->interview_question_set_id]));

        Schema::table('interview_scorecard_items', function (Blueprint $table) {
            $table->dropColumn('section_index');
        });
        Schema::table('interview_scorecards', function (Blueprint $table) {
            $table->dropColumn('sections');
        });
        Schema::dropIfExists('position_question_sets');
        Schema::dropIfExists('recruitment_opening_question_sets');
    }
};
