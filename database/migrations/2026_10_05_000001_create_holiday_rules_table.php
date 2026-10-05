<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Quy tắc ngày nghỉ LẶP HẰNG NĂM (theo dương lịch hoặc âm lịch) — HolidayService
// dựa vào đây để tự sinh ngày nghỉ cụ thể của từng năm vào bảng `holidays`.
// 6 quy tắc hệ thống theo Bộ luật Lao động 2019 Điều 112 được tạo sẵn ngay trong
// migration (DB đang chạy cũng có, không phụ thuộc seeder). HR sửa được độ lệch/số
// ngày (vd Quốc khánh nghỉ 1/9-2/9 hay 2/9-3/9 tùy năm), bật/tắt, nhưng không xóa.
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('holiday_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('calendar', 10)->default('solar'); // solar | lunar
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');
            // Ngày bắt đầu nghỉ = ngày gốc + offset (vd Tết: -1 = nghỉ từ 30 Tết).
            $table->smallInteger('offset_days')->default(0);
            $table->unsignedTinyInteger('duration_days')->default(1);
            // Ngày lễ trùng Thứ 7/CN thì nghỉ bù vào ngày làm việc kế tiếp (Điều 112 khoản 3).
            $table->boolean('compensate_weekend')->default(false);
            $table->boolean('is_paid')->default(true);
            $table->unsignedSmallInteger('notify_days_before')->default(7);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        $now = now();
        $rules = [
            ['Tết Dương lịch', 'solar', 1, 1, 0, 1, 'Ngày 1 tháng 1 dương lịch.'],
            ['Tết Nguyên Đán', 'lunar', 1, 1, -1, 5, 'Mặc định nghỉ từ ngày cuối năm âm lịch (30 hoặc 29 Tết) tới hết mùng 4 — chỉnh theo thông báo của Chính phủ từng năm.'],
            ['Giỗ Tổ Hùng Vương', 'lunar', 3, 10, 0, 1, 'Ngày 10 tháng 3 âm lịch.'],
            ['Ngày Chiến thắng', 'solar', 4, 30, 0, 1, 'Ngày 30 tháng 4 dương lịch.'],
            ['Ngày Quốc tế Lao động', 'solar', 5, 1, 0, 1, 'Ngày 1 tháng 5 dương lịch.'],
            ['Quốc khánh', 'solar', 9, 2, 0, 2, 'Ngày 2 tháng 9 và 1 ngày liền kề (mặc định 3/9) — chỉnh độ lệch về -1 nếu năm đó nghỉ 1/9-2/9.'],
        ];

        foreach ($rules as [$name, $calendar, $month, $day, $offset, $duration, $description]) {
            DB::table('holiday_rules')->insert([
                'name' => $name,
                'calendar' => $calendar,
                'month' => $month,
                'day' => $day,
                'offset_days' => $offset,
                'duration_days' => $duration,
                'compensate_weekend' => true,
                'is_paid' => true,
                'notify_days_before' => $name === 'Tết Nguyên Đán' ? 14 : 7,
                'is_system' => true,
                'is_active' => true,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_rules');
    }
};
