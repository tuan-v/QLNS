<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;

class LeaveBalance extends Model
{
    use Auditable;
    use BroadcastsChanges;

    protected array $realtimeShared = ['employees'];

    protected array $realtimeOwn = ['leave_balances'];

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
