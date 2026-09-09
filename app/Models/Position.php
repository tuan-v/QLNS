<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Position extends Model
{
    use Auditable;
    use SoftDeletes;
    protected $fillable = [
        'department_id',
        'code',
        'name',
        'level',
        'position_allowance',
        'is_active',
        'type',
    ];
    public function department()
    {
        return $this->belongsTo(Department::class);
    }
    // Role gợi ý sẵn khi tạo tài khoản đăng nhập cho nhân viên giữ chức vụ này
    // (bảng role_positions) — chỉ là gợi ý, Admin vẫn chọn/sửa được lúc tạo
    // tài khoản, không phải quyền thật.
    public function suggestedRoles()
    {
        return $this->belongsToMany(Role::class, 'role_positions');
    }
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}
