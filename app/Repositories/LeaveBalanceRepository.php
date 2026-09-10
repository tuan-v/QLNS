<?php

namespace App\Repositories;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;

class LeaveBalanceRepository
{
    public function findOrCreateForYear(Employee $employee, LeaveType $leaveType, int $year): LeaveBalance
    {
        return LeaveBalance::firstOrCreate(
            ['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => $year],
            ['allocated_days' => $leaveType->annual_entitlement_days],
        );
    }

    // Khóa dòng lại (SELECT ... FOR UPDATE) để trừ quỹ phép an toàn khi
    // duyệt đơn (Ngày 37) — tránh 2 đơn cùng employee+loại phép được duyệt
    // gần như đồng thời cùng đọc used_days cũ rồi ghi đè, làm mất 1 lượt trừ.
    public function findForYearLocked(int $employeeId, int $leaveTypeId, int $year): LeaveBalance
    {
        return LeaveBalance::where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
