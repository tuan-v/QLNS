<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Quản lý số dư phép (2026-10-15 — quyết định người dùng; tài liệu chỉ yêu cầu "tính toán quỹ
// phép năm" / "tự động trừ quỹ phép", phần dưới là quy định nội bộ):
//  - Lịch sử mọi lần cộng/trừ số dư (HR điều chỉnh tay BẮT BUỘC lý do, phép chuyển năm,
//    phép chuyển hết hạn) — nhân viên xem được vì sao số dư đổi.
//  - Chính sách chuyển phép theo loại phép: tối đa N ngày (cột có sẵn), hạn dùng MM-DD (mới).
//  - Thanh toán phép chưa nghỉ khi nghỉ việc (BLĐ 2019 Điều 113 khoản 3) vào lương tháng cuối.
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('leave_balance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_balance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();
            $table->foreignId('leave_type_id')->constrained();
            $table->unsignedSmallInteger('year');
            // manual = HR điều chỉnh tay; carry_forward = phép chuyển từ năm trước; carry_expired = phép chuyển hết hạn.
            $table->string('type', 20);
            $table->decimal('days', 6, 2);
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'year'], 'lba_employee_year_index');
        });

        Schema::table('leave_types', function (Blueprint $table) {
            // Hạn dùng phép chuyển, dạng "MM-DD" của năm sau (vd "03-31").
            $table->string('carry_forward_expiry', 5)->nullable()->after('max_carry_forward_days');
        });

        Schema::table('leave_balances', function (Blueprint $table) {
            // Đã xử lý hết hạn phép chuyển của năm này chưa (job chạy 1 lần sau ngày hết hạn).
            $table->timestamp('carry_expired_at')->nullable()->after('used_days');
        });

        Schema::table('payroll_details', function (Blueprint $table) {
            // Số ngày phép còn lại lúc nghỉ việc (âm = đã nghỉ quá — không trừ lương) và tiền trả.
            $table->decimal('leave_payout_days', 6, 2)->default(0)->after('bonus_amount');
            $table->decimal('leave_payout_amount', 18, 2)->default(0)->after('leave_payout_days');
        });

        DB::table('leave_types')->where('code', 'annual')->update(['carry_forward_expiry' => '03-31']);

        DB::table('salary_components')->updateOrInsert(
            ['code' => 'leave_payout'],
            [
                'name' => 'Thanh toán phép chưa nghỉ',
                'component_type' => 'bonus',
                'calculation_rule' => json_encode(['source' => 'unused_annual_leave_on_termination']),
                'is_taxable' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        // Quyền điều chỉnh số dư phép — HR + Admin (sửa chính sách phép vẫn là leave.manage, chỉ Admin).
        if (DB::table('roles')->exists()) {
            DB::table('permissions')->updateOrInsert(
                ['code' => 'leave.balance_adjust', 'guard_name' => 'api'],
                ['name' => 'Điều chỉnh số dư phép của nhân viên', 'created_at' => now(), 'updated_at' => now()],
            );
            $permissionId = DB::table('permissions')->where('code', 'leave.balance_adjust')->value('id');
            foreach (DB::table('roles')->whereIn('name', ['Admin', 'HR'])->pluck('id') as $roleId) {
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], ['granted_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('code', 'leave.balance_adjust')->value('id');
        if ($id) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        $componentId = DB::table('salary_components')->where('code', 'leave_payout')->value('id');
        if ($componentId) {
            DB::table('payroll_detail_components')->where('salary_component_id', $componentId)->delete();
            DB::table('salary_components')->where('id', $componentId)->delete();
        }

        Schema::table('payroll_details', fn (Blueprint $t) => $t->dropColumn(['leave_payout_days', 'leave_payout_amount']));
        Schema::table('leave_balances', fn (Blueprint $t) => $t->dropColumn('carry_expired_at'));
        Schema::table('leave_types', fn (Blueprint $t) => $t->dropColumn('carry_forward_expiry'));
        Schema::dropIfExists('leave_balance_adjustments');
    }
};
