<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ứng viên đồng ý / từ chối lịch phỏng vấn — HR ghi nhận rồi hệ thống báo Admin.
// Xem RecruitmentService::recordInterviewConfirmation().
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('recruitment_interviews', function (Blueprint $table) {
            // pending | confirmed | reschedule_requested | withdrawn
            $table->string('confirmation_status', 30)->default('pending')->after('status')->index();
            $table->text('confirmation_note')->nullable()->after('confirmation_status');
            $table->foreignId('confirmed_by')->nullable()->after('confirmation_note')->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_interviews', function (Blueprint $table) {
            $table->dropIndex(['scheduled_at']);
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['confirmation_status', 'confirmation_note', 'confirmed_at']);
        });
    }
};
