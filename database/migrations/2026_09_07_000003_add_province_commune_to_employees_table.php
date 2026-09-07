<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // "addresses" trước đây là 1 chuỗi tự do — đổi tên thành
            // "address_detail" (chỉ còn số nhà/tên đường), phần Tỉnh/Xã tách
            // riêng qua 2 khóa ngoại bên dưới.
            $table->renameColumn('addresses', 'address_detail');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedInteger('province_code')->nullable()->after('address_detail');
            $table->unsignedInteger('commune_code')->nullable()->after('province_code');

            $table->foreign('province_code')->references('code')->on('provinces')->nullOnDelete();
            $table->foreign('commune_code')->references('code')->on('communes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['province_code']);
            $table->dropForeign(['commune_code']);
            $table->dropColumn(['province_code', 'commune_code']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->renameColumn('address_detail', 'addresses');
        });
    }
};
