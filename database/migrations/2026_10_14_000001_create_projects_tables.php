<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Dự án & công việc (2026-10-14, giai đoạn A — ngoài 2 tài liệu gốc, người dùng duyệt).
// Admin/HR (project.manage) tạo dự án + chọn Lead; Lead thêm thành viên, giao việc;
// thành viên làm xong -> "chờ duyệt" -> Lead duyệt kèm điểm chất lượng 1-5 hoặc trả lại.
// Dữ liệu này là nguồn tính KPI (giai đoạn B).
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_employee_id')->constrained('employees');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        // Lead cũng là 1 dòng ở đây (role = lead) — mọi kiểm tra "là thành viên" chỉ cần 1 bảng.
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained();
            $table->string('role', 10)->default('member');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'employee_id']);
        });

        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assignee_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('priority', 10)->default('medium');
            // Độ khó / khối lượng (1,2,3,5,8) — trọng số khi tính KPI.
            $table->unsignedTinyInteger('points')->default(1);
            $table->date('start_date')->nullable();
            $table->date('due_date');
            $table->string('status', 20)->default('todo');
            // Lần nộp gần nhất (dùng xét đúng hạn) và lúc Lead duyệt xong.
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Ghi chú khi nộp (người làm) / nhận xét khi duyệt hoặc lý do trả lại (Lead).
            $table->text('submission_note')->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedSmallInteger('return_count')->default(0);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            // Chống nhắc trùng (job projects:remind-tasks).
            $table->timestamp('due_soon_notified_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status']);
            $table->index(['assignee_employee_id', 'status']);
            $table->index('due_date');
        });

        // Quyền cho DB ĐANG CHẠY — bản cài mới nhận qua seeder.
        if (DB::table('roles')->exists()) {
            DB::table('permissions')->updateOrInsert(
                ['code' => 'project.manage', 'guard_name' => 'api'],
                ['name' => 'Quản lý dự án (tạo dự án, chọn Lead, xem mọi dự án)', 'created_at' => now(), 'updated_at' => now()],
            );
            $permissionId = DB::table('permissions')->where('code', 'project.manage')->value('id');
            foreach (DB::table('roles')->whereIn('name', ['Admin', 'HR'])->pluck('id') as $roleId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['granted_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');

        $id = DB::table('permissions')->where('code', 'project.manage')->value('id');
        if ($id) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
