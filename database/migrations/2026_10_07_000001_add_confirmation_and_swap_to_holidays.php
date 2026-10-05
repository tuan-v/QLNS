<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Lịch nghỉ "bán tự động": hệ thống tự tính theo luật, HR đối chiếu thông báo
// chính thức của Nhà nước rồi XÁC NHẬN; chỉ dịp đã xác nhận mới tự thông báo cho
// nhân viên. Thêm:
//   - holiday_rules.auto_adjacent: tự chọn ngày liền kề nối với cuối tuần (Quốc khánh).
//   - holiday_rules.confirm_remind_days_before: nhắc HR xác nhận trước bao nhiêu ngày.
//   - holidays.confirmed_at/confirmed_by, confirm_reminded_at.
//   - holidays.makeup_date: ngày nghỉ hoán đổi (source = swap) kèm ngày đi làm bù
//     (Thứ 7/CN) — ngày làm bù được chấm công như ngày thường bị thay thế.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('holiday_rules', function (Blueprint $table) {
            $table->boolean('auto_adjacent')->default(false)->after('offset_days');
            $table->unsignedSmallInteger('confirm_remind_days_before')->default(45)->after('notify_days_before');
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->date('makeup_date')->nullable()->after('holiday_date')->index();
            $table->timestamp('confirmed_at')->nullable()->after('notified_at');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('confirm_reminded_at')->nullable()->after('confirmed_by');
        });

        DB::table('holiday_rules')->where('is_system', true)->where('name', 'Quốc khánh')->update([
            'auto_adjacent' => true,
            'description' => 'Ngày 2 tháng 9 và 1 ngày liền kề — hệ thống tự chọn 1/9 hoặc 3/9 để nối với cuối tuần (2/9 rơi Thứ 4 thì chọn 1/9). Đối chiếu thông báo chính thức rồi xác nhận.',
        ]);
        DB::table('holiday_rules')->where('is_system', true)->where('name', 'Tết Nguyên Đán')->update([
            'confirm_remind_days_before' => 60,
        ]);

        // Dữ liệu đã có: ngày đã qua và ngày HR tự thêm coi như đã xác nhận.
        DB::table('holidays')
            ->where(fn ($q) => $q->where('holiday_date', '<', now()->toDateString())->orWhere('source', 'manual'))
            ->update(['confirmed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['makeup_date', 'confirmed_at', 'confirm_reminded_at']);
        });

        Schema::table('holiday_rules', function (Blueprint $table) {
            $table->dropColumn(['auto_adjacent', 'confirm_remind_days_before']);
        });
    }
};
