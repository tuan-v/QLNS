<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $table) {
            // Mã hành chính chuẩn của Tổng cục Thống kê (GSO), lấy nguyên trạng
            // từ nguồn dữ liệu ngoài (xem ProvinceCommuneSeeder) — không tự sinh
            // id riêng để khỏi phải map ngược mỗi lần đối chiếu dữ liệu.
            $table->unsignedInteger('code')->primary();
            $table->string('name', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provinces');
    }
};
