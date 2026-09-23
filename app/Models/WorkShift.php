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
        'break_start_time',
        'break_end_time',
        'standard_work_minutes',
        'late_grace_minutes',
        'early_leave_grace_minutes',
        'work_coefficient',
        'is_active',
        'is_default',
        'work_days',
    ];

    // Cột decimal MySQL trả về chuỗi thô ("0.50") nếu không ép kiểu — hiện
    // xấu ở Frontend (giống lý do đã sửa ở LeaveRequest/LeaveType, mục 19).
    // KHÔNG ép is_active thành boolean: WorkShifts.vue map trạng thái theo
    // khóa số nguyên 1/0 (ACTIVE_STATUS_MAP), ép boolean sẽ đổi JSON true/
    // false làm StatusChip không khớp được khóa nào — đã vấp lỗi này thật,
    // xem CODE_MAP mục 19. is_default KHÁC is_active: chỉ dùng làm cờ true/
    // false thuần túy (v-switch ở Settings.vue/WorkShiftForm.vue), không có
    // StatusChip nào tra theo khóa số nguyên của nó — ép boolean an toàn.
    protected $casts = [
        'work_coefficient' => 'float',
        'is_default' => 'boolean',
        // Chỉ CA MẶC ĐỊNH thật sự dùng cột này (xem migration
        // add_work_days_to_work_shifts_table) — NULL nếu chưa từng cấu hình
        // ở trang Cài đặt, EmployeeShiftAssignmentService::assignDefaultShift()
        // tự rơi về T2-T6 khi đó.
        'work_days' => 'array',
    ];

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }
}
