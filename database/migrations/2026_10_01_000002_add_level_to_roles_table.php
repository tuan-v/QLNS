<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Cấp bậc vai trò" (2026-10-01, theo yêu cầu người dùng: HR hay người quyền
// thấp hơn Admin khi tạo tài khoản cho nhân viên mới KHÔNG được gán vai trò
// cao hơn hoặc NGANG BẰNG chính mình) — xem RoleService::assertCanAssign().
//
// Vì sao phải thêm cột thay vì so tập quyền của 2 vai trò: tập quyền trong dự
// án này KHÔNG phân cấp mà trực giao nhau — HR giữ quyền quản lý người khác
// (employee.*, payroll.manage, leave.approve_hr...) còn Employee giữ quyền tự
// phục vụ (attendance.check, leave.request...). Không một quyền nào của
// Employee nằm trong tập của HR (xem RolePermissionSeeder), nên luật "chỉ gán
// được vai trò có tập quyền nhỏ hơn tập của mình" sẽ chặn luôn cả việc HR tạo
// tài khoản Employee — tức hỏng đúng việc chính của HR, và làm đỏ test
// HardeningTest::test_hr_can_still_create_accounts_with_ordinary_roles().
//
// Mặc định 100 (cấp cao nhất) là CHỦ Ý fail-closed: vai trò mới tạo mà quên
// đặt cấp bậc thì chỉ Admin gán được, thay vì ai cũng gán được — tránh biến
// một vai trò nhiều quyền mới tạo thành lỗ leo thang đặc quyền.
return new class () extends Migration {
    private const LEVELS = [
        'Admin' => 100,
        'HR' => 60,
        'Manager' => 40,
        'Employee' => 10,
    ];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedSmallInteger('level')->default(100)->after('description');
        });

        // 4 vai trò gốc do seeder tạo: hạ cấp đúng thứ bậc. Vai trò do người
        // dùng tự tạo trước migration này giữ mặc định 100 (chỉ Admin gán
        // được) — Admin tự hạ cấp sau ở màn Vai trò nếu muốn giao cho HR.
        foreach (self::LEVELS as $name => $level) {
            DB::table('roles')->where('name', $name)->update(['level' => $level]);
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
