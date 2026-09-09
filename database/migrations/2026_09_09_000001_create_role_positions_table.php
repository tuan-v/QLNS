<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    // Bảng gợi ý: Chức vụ X gợi ý sẵn Role Y khi tạo tài khoản đăng nhập cho
    // nhân viên giữ chức vụ đó — không phải bảng cấp quyền thật (đó là
    // role_permissions/user_roles), nên không cần granted_by/granted_at.
    public function up(): void
    {
        Schema::create('role_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['position_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_positions');
    }
};
