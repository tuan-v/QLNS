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
        'late_minutes',
        'early_leave_minutes',
        'status',
        'note',
    ];

    protected $casts = [
        'attendance_date' => 'date:Y-m-d',
        'first_check_in_at' => 'datetime',
        'last_check_out_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function workShift()
    {
        return $this->belongsTo(WorkShift::class);
    }

    public function logs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function adjustments()
    {
        return $this->hasMany(AttendanceAdjustment::class);
    }
}
