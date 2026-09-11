<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class LeaveRequest extends Model
{
    use Auditable;
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'from_date',
        'to_date',
        'start_session',
        'end_session',
        'total_days',
        'reason',
        'evidence_file_path',
        'status',
        'submitted_at',
        'cancelled_at',
    ];

    protected $casts = [
        'from_date' => 'date:Y-m-d',
        'to_date' => 'date:Y-m-d',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }
    public function approvals()
    {
        return $this->hasMany(LeaveApproval::class);
    }
}
