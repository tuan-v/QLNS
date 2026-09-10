<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendanceAdjustment extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'type',
        'attendance_id',
        'employee_id',
        'work_shift_id',
        'attendance_date',
        'requested_by',
        'approved_by',
        'proposed_check_in_at',
        'proposed_check_out_at',
        'reason',
        'status',
        'decision_note',
        'decided_at',
    ];

    protected $casts = [
        'attendance_date' => 'date:Y-m-d',
        'proposed_check_in_at' => 'datetime',
        'proposed_check_out_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function attendance()
    {
        return $this->belongsTo(Attendance::class);
    }

    // employee_id/work_shift_id/attendance_date được set cho CẢ 2 loại
    // (correction lẫn supplement) — xem migration
    // 2026_09_11_000002_add_type_and_shift_fields..., không chỉ đọc được
    // qua attendance (attendance_id null lúc type=supplement chưa duyệt).
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function workShift()
    {
        return $this->belongsTo(WorkShift::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
