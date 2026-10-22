<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// KPI tháng từ công việc dự án (giai đoạn B, 2026-10-19). Trong tháng KPI tính trực tiếp từ project_tasks;
// sau khi hết tháng + KpiService::LOCK_AFTER_DAYS ngày, lệnh kpi:lock-months chụp lại kết quả vào
// kpi_monthly_results (mỗi nhân viên × dự án × tháng 1 dòng) để số tháng cũ không đổi nữa.
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('kpi_month_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->timestamp('locked_at');
            $table->timestamps();

            $table->unique(['year', 'month']);
        });

        Schema::create('kpi_monthly_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('employee_id')->constrained();
            $table->foreignId('project_id')->constrained();
            $table->unsignedInteger('task_count');
            $table->unsignedInteger('done_count');
            $table->unsignedInteger('points_due');
            $table->unsignedInteger('points_done');
            $table->unsignedInteger('points_on_time');
            // Σ (điểm độ khó × mức chất lượng) của việc đã hoàn thành — chia points_done ra chất lượng TB.
            $table->unsignedInteger('quality_points');
            $table->unsignedInteger('return_count');
            // Bản chụp danh sách việc được tính (để đối chiếu khi có thắc mắc).
            $table->json('tasks');
            $table->timestamps();

            $table->unique(['year', 'month', 'employee_id', 'project_id']);
        });

        // Quyền cho DB ĐANG CHẠY — bản cài mới nhận qua seeder.
        if (DB::table('roles')->exists()) {
            DB::table('permissions')->updateOrInsert(
                ['code' => 'kpi.view_all', 'guard_name' => 'api'],
                ['name' => 'Xem KPI của toàn bộ nhân viên', 'created_at' => now(), 'updated_at' => now()],
            );
            $permissionId = DB::table('permissions')->where('code', 'kpi.view_all')->value('id');
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
        Schema::dropIfExists('kpi_monthly_results');
        Schema::dropIfExists('kpi_month_locks');

        $id = DB::table('permissions')->where('code', 'kpi.view_all')->value('id');
        if ($id) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
