<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phiếu chấm phỏng vấn: Admin soạn bộ câu hỏi (trọng số + % đạt) và gắn cho đợt tuyển;
// HR/Admin chấm 1–5 sao từng câu trong buổi phỏng vấn -> % -> tự ghi Đạt/Không đạt.
// Phiếu CHÉP câu hỏi lúc tạo — sửa bộ câu hỏi sau đó không đổi phiếu cũ. Xem InterviewScorecardService.
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('interview_question_sets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->decimal('pass_percent', 5, 2); // % tối thiểu để Đạt
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('interview_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_question_set_id')->constrained('interview_question_sets')->cascadeOnDelete();
            $table->text('content');
            $table->text('guidance')->nullable(); // gợi ý đánh giá cho người chấm
            $table->unsignedSmallInteger('weight');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('recruitment_openings', function (Blueprint $table) {
            $table->foreignId('interview_question_set_id')->nullable()->after('position_id')
                ->constrained('interview_question_sets')->nullOnDelete();
        });

        Schema::create('interview_scorecards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_interview_id')->unique()->constrained('recruitment_interviews')->cascadeOnDelete();
            $table->foreignId('interview_question_set_id')->nullable()->constrained('interview_question_sets')->nullOnDelete();
            $table->string('question_set_name', 255);
            $table->decimal('pass_percent', 5, 2);
            $table->string('status', 20)->default('draft'); // draft | submitted
            $table->decimal('score_percent', 5, 2)->nullable();
            $table->string('result', 20)->nullable(); // passed | failed
            $table->text('overall_note')->nullable();
            $table->foreignId('scored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('interview_scorecard_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_scorecard_id')->constrained('interview_scorecards')->cascadeOnDelete();
            $table->text('content');
            $table->text('guidance')->nullable();
            $table->unsignedSmallInteger('weight');
            $table->unsignedTinyInteger('score')->nullable(); // 1..5
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_scorecard_items');
        Schema::dropIfExists('interview_scorecards');
        Schema::table('recruitment_openings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('interview_question_set_id');
        });
        Schema::dropIfExists('interview_questions');
        Schema::dropIfExists('interview_question_sets');
    }
};
