<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Thư mời nhận việc (Offer) — bước giữa "Đạt phỏng vấn" và "Nhận việc". HR soạn, Admin
// duyệt, ứng viên trả lời qua link bảo mật trong email. Xem RecruitmentOfferService.
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('recruitment_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recruitment_candidate_id')->constrained('recruitment_candidates')->cascadeOnDelete();
            $table->string('contract_type', 30);
            $table->decimal('salary', 15, 2);
            $table->date('start_date');
            $table->date('response_deadline');
            $table->text('message')->nullable(); // lời nhắn gửi ứng viên trong email
            // pending_approval | rejected | sent | accepted | declined | expired | withdrawn
            $table->string('status', 20)->default('pending_approval')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable(); // lý do Admin không duyệt (nội bộ)
            // Chỉ lưu HASH của mã trong link email (lộ DB cũng không dùng được link).
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->text('response_note')->nullable(); // lý do ứng viên từ chối
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_offers');
    }
};
