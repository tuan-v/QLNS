<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Phụ cấp & trợ cấp (Kế hoạch Ngày 48 "Cấu hình công thức lương, phụ cấp"; YEU_CAU mục 3.1 dòng 7).
// Danh mục dùng lại bảng salary_components (component_type = allowance | subsidy) và thêm cấu hình:
// cách tính theo ngày công, phần miễn thuế TNCN, có tính vào lương đóng BH, mức gợi ý theo loại hợp
// đồng + Trưởng phòng. Mức của từng nhân viên ở employee_allowances (có ngày hiệu lực, giữ lịch sử).
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('salary_components', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->after('name');
            // fixed | per_workday | prorated — xem AllowanceService::METHODS.
            $table->string('calculation_method', 20)->nullable()->after('component_type');
            // Phần được miễn thuế mỗi tháng (vd ăn ca 730.000đ); null = chịu thuế toàn bộ khi is_taxable.
            $table->decimal('tax_free_amount', 15, 2)->nullable()->after('is_taxable');
            $table->boolean('in_insurance_salary')->default(false)->after('tax_free_amount');
            // {"chinh_thuc": .., "thu_viec": .., "thuc_tap": .., "head": .. | null}
            $table->json('suggested_amounts')->nullable()->after('in_insurance_salary');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('suggested_amounts');
        });

        Schema::create('employee_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->foreignId('salary_component_id')->constrained('salary_components');
            $table->decimal('amount', 15, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            // Bắt buộc khi mức khác mức gợi ý.
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'salary_component_id', 'effective_from'], 'employee_allowances_lookup');
        });

        // Danh mục mặc định — số tiền để HR chỉnh lại theo quy chế công ty.
        $defaults = [
            [
                'code' => 'allowance_meal', 'name' => 'Ăn trưa', 'sort_order' => 1,
                'description' => 'Tiền ăn giữa ca, trả theo số ngày đi làm thực tế.',
                'calculation_method' => 'per_workday', 'is_taxable' => true, 'tax_free_amount' => 730000, 'in_insurance_salary' => false,
                'suggested_amounts' => ['chinh_thuc' => 730000, 'thu_viec' => 730000, 'thuc_tap' => 500000, 'head' => null],
            ],
            [
                'code' => 'allowance_travel', 'name' => 'Xăng xe, đi lại', 'sort_order' => 2,
                'description' => 'Hỗ trợ đi lại hằng tháng, tính theo tỉ lệ ngày hưởng lương.',
                'calculation_method' => 'prorated', 'is_taxable' => true, 'tax_free_amount' => null, 'in_insurance_salary' => false,
                'suggested_amounts' => ['chinh_thuc' => 500000, 'thu_viec' => 300000, 'thuc_tap' => 0, 'head' => null],
            ],
            [
                'code' => 'allowance_phone', 'name' => 'Điện thoại', 'sort_order' => 3,
                'description' => 'Khoán điện thoại theo quy chế công ty, không tính vào thu nhập chịu thuế.',
                'calculation_method' => 'fixed', 'is_taxable' => false, 'tax_free_amount' => null, 'in_insurance_salary' => false,
                'suggested_amounts' => ['chinh_thuc' => 200000, 'thu_viec' => 0, 'thuc_tap' => 0, 'head' => 500000],
            ],
            [
                'code' => 'allowance_responsibility', 'name' => 'Trách nhiệm', 'sort_order' => 4,
                'description' => 'Phụ cấp cho Trưởng phòng; ghi trong hợp đồng nên tính vào lương đóng bảo hiểm.',
                'calculation_method' => 'fixed', 'is_taxable' => true, 'tax_free_amount' => null, 'in_insurance_salary' => true,
                'suggested_amounts' => ['chinh_thuc' => 0, 'thu_viec' => 0, 'thuc_tap' => 0, 'head' => 2000000],
            ],
        ];
        foreach ($defaults as $row) {
            if (DB::table('salary_components')->where('code', $row['code'])->exists()) {
                continue;
            }
            DB::table('salary_components')->insert(array_merge($row, [
                'component_type' => 'allowance',
                'suggested_amounts' => json_encode($row['suggested_amounts']),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_allowances');
        DB::table('salary_components')
            ->whereIn('code', ['allowance_meal', 'allowance_travel', 'allowance_phone', 'allowance_responsibility'])
            ->whereNotExists(fn ($q) => $q->from('payroll_detail_components')->whereColumn('salary_component_id', 'salary_components.id'))
            ->delete();
        Schema::table('salary_components', function (Blueprint $table) {
            $table->dropColumn(['description', 'calculation_method', 'tax_free_amount', 'in_insurance_salary', 'suggested_amounts', 'sort_order']);
        });
    }
};
