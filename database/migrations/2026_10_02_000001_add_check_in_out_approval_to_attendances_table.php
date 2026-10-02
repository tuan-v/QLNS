<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Duyệt TÁCH RIÊNG giờ VÀO và giờ RA (thay cho "xin miễn trừ đi muộn"/"xin về sớm").
// Mỗi bản ghi có 2 trạng thái duyệt độc lập; cột approval_status cũ được GIỮ làm
// trạng thái TỔNG HỢP (Attendance::computeOverallApproval()) để lương/lịch sử/tổng
// hợp đang đọc cột này vẫn chạy: chỉ "approved" khi đã chấm ra và cả vào lẫn ra đều
// được duyệt; có một bên bị từ chối thì "rejected"; còn lại "pending".
// Dữ liệu cũ: bản ghi đã có 1 lần duyệt được coi là cả vào và ra đều cùng kết quả đó
// (để lương các tháng trước không đổi); chưa chấm ra thì giờ ra để pending.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->string('check_in_approval_status', 20)->default('pending')->after('approval_note');
            $table->foreignId('check_in_approved_by')->nullable()->after('check_in_approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('check_in_approved_at')->nullable()->after('check_in_approved_by');
            $table->text('check_in_approval_note')->nullable()->after('check_in_approved_at');

            $table->string('check_out_approval_status', 20)->default('pending')->after('check_in_approval_note');
            $table->foreignId('check_out_approved_by')->nullable()->after('check_out_approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('check_out_approved_at')->nullable()->after('check_out_approved_by');
            $table->text('check_out_approval_note')->nullable()->after('check_out_approved_at');
        });

        DB::table('attendances')->update([
            'check_in_approval_status' => DB::raw('approval_status'),
            'check_in_approved_by' => DB::raw('approved_by'),
            'check_in_approved_at' => DB::raw('approved_at'),
            'check_in_approval_note' => DB::raw('approval_note'),
        ]);

        DB::table('attendances')->whereNotNull('last_check_out_at')->update([
            'check_out_approval_status' => DB::raw('approval_status'),
            'check_out_approved_by' => DB::raw('approved_by'),
            'check_out_approved_at' => DB::raw('approved_at'),
            'check_out_approval_note' => DB::raw('approval_note'),
        ]);
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('check_in_approved_by');
            $table->dropConstrainedForeignId('check_out_approved_by');
            $table->dropColumn([
                'check_in_approval_status', 'check_in_approved_at', 'check_in_approval_note',
                'check_out_approval_status', 'check_out_approved_at', 'check_out_approval_note',
            ]);
        });
    }
};
