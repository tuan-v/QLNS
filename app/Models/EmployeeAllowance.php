<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BroadcastsChanges;
use Illuminate\Database\Eloquent\Model;

// Mức phụ cấp / trợ cấp của 1 nhân viên cho 1 khoản, có hiệu lực từ ngày..đến ngày. Đổi mức = đóng dòng
// cũ (effective_to) và thêm dòng mới, nên bảng này cũng là lịch sử thay đổi (xem AllowanceService::set()).
class EmployeeAllowance extends Model
{
    use Auditable;
    use BroadcastsChanges;

    protected array $realtimeShared = ['employee_allowances'];

    protected array $realtimeOwn = ['allowances'];

    protected $fillable = [
        'employee_id', 'salary_component_id', 'amount', 'effective_from', 'effective_to', 'note', 'created_by', 'approved_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function component()
    {
        return $this->belongsTo(SalaryComponent::class, 'salary_component_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
