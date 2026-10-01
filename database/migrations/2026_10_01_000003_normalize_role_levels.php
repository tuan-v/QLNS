<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Đưa roles.level về đúng thang bậc rời rạc Role::LEVELS (0/20/40/60/80/100).
// Giao diện chọn cấp bậc là ô select theo thang này, nên giá trị lệch thang
// (vd Employee = 10 do migration 2026_10_01_000002 đặt, hoặc cấp tự nhập khi ô
// còn là ô số tự do) sẽ hiện ra select trống và sửa lại là mất cấp cũ.
//
// Thứ bậc TƯƠNG ĐỐI không đổi sau khi làm tròn (Employee 10 -> 20 vẫn thấp hơn
// Manager 40 < HR 60 < Admin 100), nên luật ai-gán-được-vai-trò-nào giữ nguyên
// hành vi — xem RoleService::assignableRolesQuery().
return new class () extends Migration {
    public function up(): void
    {
        foreach (DB::table('roles')->select('id', 'level')->get() as $role) {
            $nearest = $this->nearestLevel((int) $role->level);

            if ($nearest !== (int) $role->level) {
                DB::table('roles')->where('id', $role->id)->update(['level' => $nearest]);
            }
        }
    }

    // Làm tròn LÊN khi ở giữa 2 bậc (vd 10 -> 20, không phải 0): cấp cao hơn là
    // phía chặt hơn, ít người gán được vai trò đó hơn.
    private function nearestLevel(int $level): int
    {
        $levels = Role::LEVELS;
        usort($levels, fn (int $a, int $b) => abs($a - $level) <=> abs($b - $level) ?: $b <=> $a);

        return $levels[0];
    }

    public function down(): void
    {
        // Không hoàn tác: cấp cũ lệch thang không còn ý nghĩa gì để khôi phục.
    }
};
