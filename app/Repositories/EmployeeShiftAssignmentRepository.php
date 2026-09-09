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
