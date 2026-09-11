<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkShift extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'start_time',
        'end_time',
        'break_minutes',
        'standard_work_minutes',
        'late_grace_minutes',
        'early_leave_grace_minutes',
        'work_coefficient',
        'is_active',
    ];

    // Cột decimal MySQL trả về chuỗi thô ("0.50") nếu không ép kiểu — hiện
    // xấu ở Frontend (giống lý do đã sửa ở LeaveRequest/LeaveType, mục 19).
    // KHÔNG ép is_active thành boolean: WorkShifts.vue map trạng thái theo
    // khóa số nguyên 1/0 (ACTIVE_STATUS_MAP), ép boolean sẽ đổi JSON true/
    // false làm StatusChip không khớp được khóa nào — đã vấp lỗi này thật,
    // xem CODE_MAP mục 19.
    protected $casts = [
        'work_coefficient' => 'float',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}
