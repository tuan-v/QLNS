<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Gỡ hẳn module Onboarding/Offboarding (người dùng quyết định bỏ, 2026-10-12): xóa 4 bảng
// checklist, quyền onboarding.* và thông báo checklist.*. Migration tạo cũ (2026_10_10_*)
// giữ nguyên để lịch sử migrate của DB đang chạy không lệch; cài mới thì tạo rồi xóa.
return new class () extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('employee_checklist_items');
        Schema::dropIfExists('employee_checklists');
        Schema::dropIfExists('checklist_template_items');
        Schema::dropIfExists('checklist_templates');

        $permissionIds = DB::table('permissions')->whereIn('code', ['onboarding.manage', 'onboarding.team'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        if (Schema::hasTable('notifications')) {
            DB::table('notifications')->whereIn('type', ['checklist.mine', 'checklist.team'])->delete();
        }
    }

    public function down(): void
    {
        // Không khôi phục: module đã bị gỡ khỏi code.
    }
};
