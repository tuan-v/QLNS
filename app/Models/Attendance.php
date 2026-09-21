<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attendance extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'employee_id',
        'work_shift_id',
        'attendance_date',
        'first_check_in_at',
        'last_check_out_at',
        'scheduled_work_minutes',
        'actual_work_minutes',
        'overtime_minutes',
        'overtime_approved',
        'late_minutes',
        'early_leave_minutes',
        'late_excused',
        'status',
        'approval_status',
        'approved_by',
        'approved_at',
        'approval_note',
        'note',
    ];

    // 3 giá trị của cột approval_status — bước HR duyệt chấm công (tách khỏi
    // `status` là trạng thái ca làm việc). Chỉ bản ghi 'approved' mới được
    // tính công/lương (PayrollService, AttendanceService::summarizeHistory()).
    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    protected $casts = [
        'attendance_date' => 'date:Y-m-d',
        'first_check_in_at' => 'datetime',
        'last_check_out_at' => 'datetime',
        'approved_at' => 'datetime',
        // An toàn ép boolean ở đây — khác bẫy đã vấp ở WorkShift/Position
        // (mục 19 CODE_MAP): late_excused/overtime_approved KHÔNG có
        // Frontend nào tra theo khóa số nguyên 1/0, chỉ dùng như cờ
        // true/false thuần túy.
        'late_excused' => 'boolean',
        'overtime_approved' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function workShift()
    {
        return $this->belongsTo(WorkShift::class);
    }

    // Luôn theo thứ tự thời gian (vào trước, ra sau) — màn "Duyệt chấm công" lấy
    // log đầu tiên làm lượt vào, dialog chi tiết cũng liệt kê theo đúng thứ tự
    // đó; không thì thứ tự phụ thuộc vào cách DB trả về.
    public function logs()
    {
        return $this->hasMany(AttendanceLog::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function adjustments()
    {
        return $this->hasMany(AttendanceAdjustment::class);
    }

    // Người (HR) đã duyệt/từ chối — cùng tên quan hệ với AttendanceAdjustment.
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
