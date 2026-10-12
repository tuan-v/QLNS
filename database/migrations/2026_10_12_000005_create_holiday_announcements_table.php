<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Văn bản "Thông báo nghỉ lễ" (mẫu Word của công ty) — ngoài tài liệu gốc, người dùng duyệt
// 2026-10-12. Mỗi dịp nghỉ (group_code) một văn bản; HR soạn trên web, xem trước, tải .docx,
// phát hành thì gửi thông báo trong app. Dịp có thưởng: tiền thưởng cộng vào bảng lương của
// tháng HR chọn (payroll_details.bonus_amount, chịu thuế TNCN như thu nhập từ tiền lương).
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('holiday_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('group_code', 100)->unique();
            $table->string('document_number', 100)->nullable();
            $table->date('issued_date')->nullable();
            // Toàn bộ nội dung văn bản (trường tĩnh + phần thưởng) — xem HolidayAnnouncementService::defaults().
            $table->json('content');
            $table->boolean('has_bonus')->default(false);
            // [{label, category: official|probation|intern|null, amount, note}]
            $table->json('bonus_rows')->nullable();
            $table->unsignedTinyInteger('bonus_payroll_month')->nullable();
            $table->unsignedSmallInteger('bonus_payroll_year')->nullable();
            $table->string('status', 20)->default('draft');
            // Bản chụp ngày nghỉ/đi làm lại/làm bù lúc phát hành — văn bản đã phát hành không đổi theo lịch.
            $table->json('schedule_snapshot')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bonus_payroll_year', 'bonus_payroll_month'], 'ha_bonus_period_index');
        });

        Schema::table('payroll_details', function (Blueprint $table) {
            $table->decimal('bonus_amount', 18, 2)->default(0)->after('overtime_amount');
        });

        DB::table('salary_components')->updateOrInsert(
            ['code' => 'holiday_bonus'],
            [
                'name' => 'Thưởng lễ',
                'component_type' => 'bonus',
                'calculation_rule' => json_encode(['source' => 'holiday_announcements']),
                'is_taxable' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $componentId = DB::table('salary_components')->where('code', 'holiday_bonus')->value('id');
        if ($componentId) {
            DB::table('payroll_detail_components')->where('salary_component_id', $componentId)->delete();
            DB::table('salary_components')->where('id', $componentId)->delete();
        }

        Schema::table('payroll_details', function (Blueprint $table) {
            $table->dropColumn('bonus_amount');
        });

        Schema::dropIfExists('holiday_announcements');
    }
};
