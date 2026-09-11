<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class LeaveBalance extends Model
{
    use Auditable;
    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'allocated_days',
        'carried_forward_days',
        'adjusted_days',
        'used_days',
    ];
    // Cùng lý do ép float ở LeaveRequest::$casts.
    protected $casts = [
        'allocated_days' => 'float',
        'carried_forward_days' => 'float',
        'adjusted_days' => 'float',
        'used_days' => 'float',
    ];
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }
}
