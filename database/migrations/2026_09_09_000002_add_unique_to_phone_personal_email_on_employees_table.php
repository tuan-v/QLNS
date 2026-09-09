<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    // Trước khi có migration này, phone/personal_email không unique ở cả tầng
    // Request (StoreEmployeeRequest/UpdateEmployeeRequest) lẫn DB — 2 nhân viên
    // tạo trùng cả 2 trường này lọt qua được. Xóa dữ liệu trùng ở bản ghi ĐÃ xóa
    // mềm trước khi thêm unique, nếu không ALTER TABLE sẽ báo lỗi trùng khóa
    // với dữ liệu cũ đã tồn tại từ trước; không đụng tới bản ghi đang hoạt động.
    public function up(): void
    {
        $duplicatePhones = DB::table('employees')
            ->whereNotNull('phone')
            ->groupBy('phone')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('phone');

        if ($duplicatePhones->isNotEmpty()) {
            DB::table('employees')
                ->whereNotNull('deleted_at')
                ->whereIn('phone', $duplicatePhones)
                ->update(['phone' => null]);
        }

        $duplicatePersonalEmails = DB::table('employees')
            ->whereNotNull('personal_email')
            ->groupBy('personal_email')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('personal_email');

        if ($duplicatePersonalEmails->isNotEmpty()) {
            DB::table('employees')
                ->whereNotNull('deleted_at')
                ->whereIn('personal_email', $duplicatePersonalEmails)
                ->update(['personal_email' => null]);
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->unique('phone');
            $table->unique('personal_email');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropUnique(['personal_email']);
        });
    }
};
