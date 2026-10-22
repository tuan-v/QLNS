<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Đề xuất thay đổi phụ cấp chờ Admin duyệt (yêu cầu 2026-10-21): HR thêm/sửa/tắt phụ cấp của nhân viên chỉ
// tạo đề xuất; Admin (quyền allowance.approve) duyệt thì mới ghi vào employee_allowances, hiệu lực từ đúng
// ngày HR chọn. Admin tự sửa thì áp dụng ngay (approved_by = chính Admin).
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('employee_allowance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salary_component_id')->constrained('salary_components');
            $table->decimal('amount', 15, 2); // 0 = không hưởng
            $table->date('effective_from');
            $table->string('note', 500)->nullable();
            // Mức đang áp dụng lúc đề xuất — để Admin thấy "cũ → mới".
            $table->decimal('previous_amount', 15, 2)->nullable();
            $table->string('previous_state', 10)->nullable(); // default | on | off | unset
            $table->string('status', 20)->default('pending')->index(); // pending | approved | rejected | cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('employee_allowance_id')->nullable()->constrained('employee_allowances')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'salary_component_id', 'status'], 'allowance_requests_lookup');
        });

        Schema::table('employee_allowances', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        // Quyền duyệt cho DB đang chạy — chỉ Admin; bản cài mới nhận qua seeder (Admin có mọi quyền).
        if (! DB::table('roles')->exists()) {
            return;
        }
        DB::table('permissions')->updateOrInsert(
            ['code' => 'allowance.approve', 'guard_name' => 'api'],
            ['name' => 'Duyệt thay đổi phụ cấp của nhân viên', 'created_at' => now(), 'updated_at' => now()],
        );
        $permissionId = DB::table('permissions')->where('code', 'allowance.approve')->value('id');
        foreach (DB::table('roles')->where('name', 'Admin')->pluck('id') as $roleId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['granted_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('code', 'allowance.approve')->value('id');
        if ($id) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        Schema::table('employee_allowances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
        });
        Schema::dropIfExists('employee_allowance_requests');
    }
};
