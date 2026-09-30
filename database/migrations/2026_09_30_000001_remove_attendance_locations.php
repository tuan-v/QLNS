<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Bỏ hẳn "Điểm chấm công" của công ty (2026-09-30, theo yêu cầu người dùng —
// trước đó hiểu nhầm là phải đối chiếu IP/vị trí với điểm khai sẵn; đúng ra
// chỉ ghi nhận IP/vị trí/thiết bị của CHÍNH người bấm chấm công).
return new class () extends Migration {
    public function up(): void
    {
        // Trạng thái "needs_review" chỉ sinh ra do không khớp điểm chấm công.
        DB::table('attendances')
            ->where('status', 'needs_review')
            ->whereNotNull('last_check_out_at')
            ->update(['status' => 'completed']);
        DB::table('attendances')
            ->where('status', 'needs_review')
            ->update(['status' => 'pending']);

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_location_id');
            $table->dropColumn('qr_reference');
        });

        Schema::dropIfExists('attendance_locations');

        $permissionIds = DB::table('permissions')->whereIn('code', ['location.view', 'location.manage'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }

    public function down(): void
    {
        // Không khôi phục: dữ liệu điểm chấm công đã bị xóa cùng bảng.
    }
};
