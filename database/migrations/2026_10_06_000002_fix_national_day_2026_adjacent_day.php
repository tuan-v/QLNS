<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Sửa dữ liệu: Quốc khánh năm 2026 nghỉ 1/9 và 2/9 (lịch chính thức), không phải
// 2/9 và 3/9 như quy tắc mặc định đã sinh. Ngày đã qua nên lệnh sinh tự động
// không tự sửa (chỉ đụng ngày từ hôm nay trở đi) — sửa thẳng một lần ở đây.
return new class () extends Migration {
    public function up(): void
    {
        $wrong = DB::table('holidays')
            ->where('holiday_date', '2026-09-03')
            ->where('source', 'rule')
            ->where('name', 'Quốc khánh')
            ->first();

        if ($wrong === null || DB::table('holidays')->where('holiday_date', '2026-09-01')->exists()) {
            return;
        }

        DB::table('holidays')->where('id', $wrong->id)->update([
            'holiday_date' => '2026-09-01',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Không hoàn tác: 1/9 là lịch nghỉ đúng của năm 2026.
    }
};
