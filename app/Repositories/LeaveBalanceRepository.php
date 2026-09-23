<?php

namespace App\Repositories;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;

class LeaveBalanceRepository
{
    // $initialAllocatedDays do LeaveAccrualService::targetAllocatedDays()
    // tính sẵn (2026-09-24, theo yêu cầu người dùng — không còn cấp thẳng
    // annual_entitlement_days) rồi truyền vào đây, Repository chỉ lo lưu.
    public function findOrCreateForYear(Employee $employee, LeaveType $leaveType, int $year, float $initialAllocatedDays): LeaveBalance
    {
        return LeaveBalance::firstOrCreate(
            ['employee_id' => $employee->id, 'leave_type_id' => $leaveType->id, 'year' => $year],
            ['allocated_days' => $initialAllocatedDays],
        );
    }

    public function update(LeaveBalance $balance, array $data): LeaveBalance
    {
        $balance->update($data);

        return $balance;
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
