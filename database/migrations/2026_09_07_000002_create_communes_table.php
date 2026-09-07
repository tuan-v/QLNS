<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('communes', function (Blueprint $table) {
            // Xã/Phường/Đặc khu — cấp hành chính thứ 2 sau đợt sáp nhập 2025 bỏ
            // cấp Huyện, nên chỉ còn quan hệ 1-nhiều thẳng Tỉnh -> Xã, không cần
            // bảng trung gian.
            $table->unsignedInteger('code')->primary();
            $table->string('name', 150);
            $table->unsignedInteger('province_code');
            $table->timestamps();

            $table->foreign('province_code')->references('code')->on('provinces')->cascadeOnDelete();
            $table->index('province_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communes');
    }
};
