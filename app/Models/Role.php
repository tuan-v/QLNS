<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\Auditable;

class Role extends Model
{
    use SoftDeletes;
    use Auditable;
    // Thang cấp bậc rời rạc (bậc 20 một) — NGUỒN CHUẨN cho cả validate ở
    // StoreRoleRequest/UpdateRoleRequest và ô chọn ở giao diện (bản mirror:
    // resources/js/composables/roleLevels.js). Chọn bậc thưa thay vì cho nhập
    // số tự do để không sinh ra những cấp lệch nhau 1-2 đơn vị vô nghĩa, và
    // vẫn còn chỗ trống (80) để chèn cấp trung gian sau.
    public const LEVELS = [0, 20, 40, 60, 80, 100];

    // Cấp bậc mặc định của vai trò mới = cao nhất, tức chỉ Admin gán được cho
    // người khác (fail-closed, xem RoleService::assignableRolesQuery()). Cột DB
    // cũng default 100 làm lưới dự phòng cho đường ghi không qua Service.
    public const DEFAULT_LEVEL = 100;

    protected $fillable = ['name', 'guard_name', 'description', 'level'];

    protected $casts = ['level' => 'integer'];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withPivot(['granted_by', 'granted_at']);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot(['assigned_by', 'assigned_at']);
    }
}
