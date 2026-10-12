<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Thông báo nghỉ lễ chỉ có hiệu lực khi người đại diện ký + đóng dấu (người dùng duyệt
// 2026-10-12): HR chốt văn bản -> in trình ký -> tải lên bản scan PDF đã ký -> phát hành.
// Trạng thái thêm "pending_signature" (cột status là chuỗi, không cần đổi kiểu).
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('holiday_announcements', function (Blueprint $table) {
            $table->string('signed_file')->nullable()->after('schedule_snapshot');
            $table->timestamp('signed_uploaded_at')->nullable()->after('signed_file');
            $table->foreignId('signed_uploaded_by')->nullable()->after('signed_uploaded_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('holiday_announcements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signed_uploaded_by');
            $table->dropColumn(['signed_file', 'signed_uploaded_at']);
        });
    }
};
