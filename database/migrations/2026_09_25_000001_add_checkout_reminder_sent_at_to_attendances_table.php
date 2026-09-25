<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Chấm công quên Check-out" (Ke-hoach-trien-khai-du-an-QLNS.docx, Ngày 42 —
// tài liệu chỉ nêu tên edge case, không quy định cách xử lý cụ thể). Cột này
// đánh dấu ĐÃ gửi thông báo nhắc chấm công ra cho đúng 1 bản ghi, để job chạy
// mỗi phút (RemindMissingCheckout, xem routes/console.php) không gửi lặp lại
// nhiều lần cho tới khi bản ghi được xử lý xong (chấm công ra hoặc HR bổ sung
// qua "Điều chỉnh công").
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->timestamp('checkout_reminder_sent_at')->nullable()->after('last_check_out_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('checkout_reminder_sent_at');
        });
    }
};
