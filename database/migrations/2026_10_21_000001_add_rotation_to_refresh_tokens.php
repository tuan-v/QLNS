<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phân biệt refresh token bị thu hồi vì XOAY VÒNG (đã đổi lấy token mới) với bị thu hồi vì BẢO MẬT
// (đăng xuất, đổi mật khẩu, khóa tài khoản). Chỉ loại xoay vòng mới được dùng lại trong vài giây ân hạn
// — xem AuthService::refresh(): F5 đúng lúc đang làm mới thì trình duyệt không kịp lưu token mới.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->timestamp('rotated_at')->nullable()->after('revoked_at');
            $table->foreignId('replaced_by_id')->nullable()->after('rotated_at')
                ->constrained('refresh_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaced_by_id');
            $table->dropColumn('rotated_at');
        });
    }
};
