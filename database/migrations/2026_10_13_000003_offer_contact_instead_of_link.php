<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Thư mời nhận việc KHÔNG còn link trả lời (người dùng duyệt 2026-10-13 — ứng viên ngại
// bấm link vì sợ lừa đảo). Email ghi thông tin người liên hệ của công ty; ứng viên gọi/nhắn/
// trả lời email cho HR, HR ghi nhận chấp nhận/từ chối trên hệ thống và Admin được thông báo.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('recruitment_offers', function (Blueprint $table) {
            $table->string('contact_name', 150)->nullable()->after('message');
            $table->string('contact_phone', 20)->nullable()->after('contact_name');
            $table->string('contact_email', 255)->nullable()->after('contact_phone');
            // HR ghi nhận phản hồi của ứng viên.
            $table->foreignId('response_recorded_by')->nullable()->after('response_note')
                ->constrained('users', 'id', 'ro_response_recorded_by_fk')->nullOnDelete();
        });

        // Link cũ không còn dùng được -> bỏ mã băm.
        DB::table('recruitment_offers')->update(['token_hash' => null]);
    }

    public function down(): void
    {
        Schema::table('recruitment_offers', function (Blueprint $table) {
            $table->dropForeign('ro_response_recorded_by_fk');
            $table->dropColumn(['contact_name', 'contact_phone', 'contact_email', 'response_recorded_by']);
        });
    }
};
