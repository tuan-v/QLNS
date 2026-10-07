<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phỏng vấn trực tiếp: chọn Tỉnh/Thành + Xã/Phường + địa chỉ chi tiết (cùng danh mục
// địa chỉ của hồ sơ nhân viên). Cột location vẫn giữ địa chỉ đầy đủ đã ghép (dùng cho
// email, hiển thị); online thì location = link, 3 cột này để trống.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('recruitment_interviews', function (Blueprint $table) {
            $table->unsignedInteger('province_code')->nullable()->after('location');
            $table->unsignedInteger('commune_code')->nullable()->after('province_code');
            $table->string('address_detail', 255)->nullable()->after('commune_code');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_interviews', function (Blueprint $table) {
            $table->dropColumn(['province_code', 'commune_code', 'address_detail']);
        });
    }
};
