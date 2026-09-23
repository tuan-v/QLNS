<?php

namespace App\Repositories;

use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use Illuminate\Support\Collection;

class EmployeeShiftAssignmentRepository
{
    public function listByEmployee(Employee $employee): Collection
    {
        return $employee->shiftAssignments()
            ->with('workShift')
            ->latest('effective_from')
            ->get();
    }

    public function listByWorkShift(int $workShiftId): Collection
    {
        return EmployeeShiftAssignment::where('work_shift_id', $workShiftId)->get();
    }

    // "Đang mở" = effective_to NULL (không giới hạn ngày kết thúc) — đúng
    // hình dạng assignDefaultShift() tạo ra, khác bản gán 1-ngày của
    // createOneOffAssignment() (effective_from = effective_to). Dùng để
    // đồng bộ lại work_days khi Cài đặt đổi ngày làm việc mặc định — xem
    // EmployeeShiftAssignmentService::resyncOpenEndedWorkDays().
    public function listOpenEndedByWorkShift(int $workShiftId): Collection
    {
        return EmployeeShiftAssignment::where('work_shift_id', $workShiftId)
            ->where('status', 'active')
            ->whereNull('effective_to')
            ->get();
    }

    public function create(array $data): EmployeeShiftAssignment
    {
        return EmployeeShiftAssignment::create($data);
    }

    public function update(EmployeeShiftAssignment $assignment, array $data): EmployeeShiftAssignment
    {
        $assignment->update($data);

        return $assignment;
    }

    public function delete(EmployeeShiftAssignment $assignment): void
    {
        $assignment->delete();
    }
}
