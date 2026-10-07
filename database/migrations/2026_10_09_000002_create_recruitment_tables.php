<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tuyển dụng: đợt tuyển (theo phòng ban + chức vụ + loại hợp đồng, giới hạn số CV
// được duyệt = số người cần tuyển) -> ứng viên/CV (HR tải lên, Admin duyệt) -> lịch
// phỏng vấn -> kết quả -> nhận việc (tạo nhân viên). Xem RecruitmentService.
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('recruitment_openings', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->string('contract_type', 30);
            $table->unsignedSmallInteger('headcount');
            $table->date('deadline')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('recruitment_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_opening_id')->constrained('recruitment_openings')->restrictOnDelete();
            $table->string('full_name', 255);
            $table->string('email', 255);
            $table->string('phone', 20)->nullable();
            $table->string('cv_file', 500);
            $table->string('cv_original_name', 255);
            $table->text('note')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignId('hired_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['recruitment_opening_id', 'email']);
        });

        Schema::create('recruitment_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_candidate_id')->constrained('recruitment_candidates')->cascadeOnDelete();
            $table->dateTime('scheduled_at');
            $table->string('format', 20);
            $table->string('location', 500)->nullable();
            $table->foreignId('interviewer_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status', 20)->default('scheduled');
            $table->string('result', 20)->nullable();
            $table->text('result_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_interviews');
        Schema::dropIfExists('recruitment_candidates');
        Schema::dropIfExists('recruitment_openings');
    }
};
