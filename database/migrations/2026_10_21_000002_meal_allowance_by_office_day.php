<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Quy chế công ty (người dùng chốt 2026-10-21): chỉ còn 2 khoản —
//  - "Phụ cấp ăn trưa" 30.000đ cho MỖI NGÀY LÀM TẠI CÔNG TY (ngày làm online không tính; nửa ngày vẫn đủ
//    1 suất). Mọi nhân viên tự hưởng (applies_to_all), HR tắt riêng ai không được.
//  - "Phụ cấp khác": HR đặt số tiền cố định/tháng + nội dung cho từng người.
// Chấm công thêm attendances.work_mode (office | online) — nhân viên chọn khi bấm chấm công vào.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('work_mode', 10)->default('office')->after('work_shift_id');
        });

        Schema::table('salary_components', function (Blueprint $table) {
            // Không có dòng employee_allowances nào = vẫn hưởng theo mức gợi ý (HR đặt 0 để tắt riêng 1 người).
            $table->boolean('applies_to_all')->default(false)->after('in_insurance_salary');
        });

        // Bỏ các khoản mẫu cũ chưa dùng vào bảng lương nào (người dùng: "còn lại bỏ những cái đang hiện có").
        $old = DB::table('salary_components')
            ->whereIn('code', ['allowance_travel', 'allowance_phone', 'allowance_responsibility'])
            ->pluck('id');
        $used = DB::table('payroll_detail_components')->whereIn('salary_component_id', $old)->pluck('salary_component_id');
        DB::table('employee_allowances')->whereIn('salary_component_id', $old->diff($used))->delete();
        DB::table('salary_components')->whereIn('id', $old->diff($used))->delete();
        DB::table('salary_components')->whereIn('id', $used)->update(['is_active' => false, 'updated_at' => now()]);

        $meal = [
            'name' => 'Phụ cấp ăn trưa',
            'description' => '30.000đ cho mỗi ngày làm tại công ty; ngày làm online không tính.',
            'component_type' => 'allowance',
            'calculation_method' => 'per_office_day',
            'is_taxable' => true,
            'tax_free_amount' => 730000, // tiền ăn giữa ca miễn thuế tới 730.000đ/tháng
            'in_insurance_salary' => false,
            'applies_to_all' => true,
            'suggested_amounts' => json_encode(['chinh_thuc' => 30000, 'thu_viec' => 30000, 'thuc_tap' => 30000, 'head' => null]),
            'sort_order' => 1,
            'is_active' => true,
            'updated_at' => now(),
        ];
        if (DB::table('salary_components')->where('code', 'allowance_meal')->exists()) {
            DB::table('salary_components')->where('code', 'allowance_meal')->update($meal);
        } else {
            DB::table('salary_components')->insert($meal + ['code' => 'allowance_meal', 'created_at' => now()]);
        }
        // Mức ăn trưa cũ tính theo THÁNG (vd 730.000đ) không còn đúng đơn vị "mỗi ngày" -> bỏ để dùng mức chung
        // 30.000đ/ngày. Giữ lại các dòng "không hưởng" (= 0).
        $mealId = DB::table('salary_components')->where('code', 'allowance_meal')->value('id');
        DB::table('employee_allowances')->where('salary_component_id', $mealId)->where('amount', '>', 0)->delete();

        if (! DB::table('salary_components')->where('code', 'allowance_other')->exists()) {
            DB::table('salary_components')->insert([
                'code' => 'allowance_other',
                'name' => 'Phụ cấp khác',
                'description' => 'Số tiền cố định mỗi tháng HR đặt riêng cho từng người, kèm nội dung.',
                'component_type' => 'allowance',
                'calculation_method' => 'fixed',
                'is_taxable' => true,
                'tax_free_amount' => null,
                'in_insurance_salary' => false,
                'applies_to_all' => false,
                'suggested_amounts' => json_encode(['chinh_thuc' => 0, 'thu_viec' => 0, 'thuc_tap' => 0, 'head' => null]),
                'sort_order' => 2,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn('work_mode');
        });
        Schema::table('salary_components', function (Blueprint $table) {
            $table->dropColumn('applies_to_all');
        });
    }
};
