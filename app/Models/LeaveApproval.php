<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class LeaveApproval extends Model
{
    use Auditable;
    protected $fillable = [
        'leave_request_id',
        'approver_employee_id',
        'approval_level',
        'decision',
        'comment',
        'decided_at',
    ];
    protected $casts = [
        'decided_at' => 'datetime',
    ];
    public function leaveRequest()
    {
        return $this->belongsTo(LeaveRequest::class);
    }
    public function approverEmployee()
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }
}
