<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mỗi lượt chấm công ghi lại 3 thông tin của CHÍNH thiết bị đang bấm (2026-09-21,
// theo yêu cầu người dùng): IP (đã có ip_address), VỊ TRÍ (latitude/longitude đã
// có, thêm address là địa chỉ chữ đổi từ tọa độ) và THIẾT BỊ (device_name dễ
// đọc + user_agent gốc để đối chiếu khi cần). Cả 3 cột mới đều nullable —
// nhân viên có thể từ chối quyền vị trí, hoặc bản ghi cũ không có dữ liệu này.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->string('address', 500)->nullable()->after('accuracy_meters');
            $table->string('device_name', 255)->nullable()->after('ip_address');
            $table->string('user_agent', 500)->nullable()->after('device_name');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn(['address', 'device_name', 'user_agent']);
        });
    }
};
