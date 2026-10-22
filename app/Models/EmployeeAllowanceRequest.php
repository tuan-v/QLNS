<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;

// Đề xuất thay đổi phụ cấp của 1 nhân viên (HR gửi, Admin duyệt) — xem AllowanceService::set()/approve().
class EmployeeAllowanceRequest extends Model
{
    use Auditable;
    use BroadcastsChanges;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected array $realtimeShared = ['allowance_requests'];

    protected $fillable = [
        'employee_id', 'salary_component_id', 'amount', 'effective_from', 'note', 'previous_amount', 'previous_state',
        'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note', 'employee_allowance_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'previous_amount' => 'float',
        'effective_from' => 'date:Y-m-d',
        'decided_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function component()
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
