<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Bước "Duyệt chấm công" (2026-09-21, theo yêu cầu người dùng: mọi lượt chấm
// công phải được HR duyệt, chưa duyệt thì không tính công/lương). TÁCH RIÊNG
// khỏi cột `status` có sẵn (pending/completed/needs_review = trạng thái CA
// LÀM VIỆC: đang trong ca / hoàn tất / không khớp điểm) — 2 trục độc lập:
// 1 bản ghi có thể 'completed' nhưng vẫn 'pending' chờ HR duyệt.
//
// Mặc định của cột là 'pending' (an toàn: bản ghi mới tạo từ bất kỳ đường nào
// cũng phải qua duyệt). Riêng các bản ghi ĐÃ CÓ trước thời điểm này được chuyển
// hàng loạt sang 'approved' ở cuối up() — nếu không, toàn bộ lịch sử chấm công
// và bảng lương các tháng trước sẽ tự nhiên "mất công" chỉ vì chưa ai duyệt.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('pending')->after('status');
            $table->foreignId('approved_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('approval_note', 1000)->nullable()->after('approved_at');

            $table->index('approval_status');
        });

        DB::table('attendances')->update([
            'approval_status' => 'approved',
            'approved_at' => now(),
            'approval_note' => 'Tự động duyệt: dữ liệu có trước khi áp dụng bước duyệt chấm công.',
        ]);
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['approval_status', 'approved_by', 'approved_at', 'approval_note']);
        });
    }
};
