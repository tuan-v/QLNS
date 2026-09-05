<?php

namespace App\Repositories;

use App\Models\Employee;
use Illuminate\Pagination\LengthAwarePaginator;

class EmployeeRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Employee::with(['department', 'position', 'manager'])->latest()->paginate($perPage);
    }
    public function find(int $id): ?Employee
    {
        return Employee::query()->with(['department', 'position', 'manager'])->find($id);
    }
    public function create(array $data): Employee
    {
        return Employee::create($data);
    }
    public function update(Employee $employee, array $data): Employee
    {
        $employee->update($data);
        return $employee;
    }
    public function delete(Employee $employee): void
    {
        $employee->delete();
    }
    public function wouldCreateCycle(int $employeeId, ?int $newManagerId): bool
    {
        if ($newManagerId === null) {
            return false; // No manager, no cycle
        }

        $newManager = Employee::find($newManagerId);

        while ($newManager !== null) {
            if ($newManager->id === $employeeId) {
                return true; // Cycle detected
            }
            $newManager = $newManager->manager;
        }

        return false; // No cycle detected
    }
    public function isSuperiorOf(Employee $target, ?Employee $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        $current = $viewer->manager;

        while ($current !== null) {
            if ($current->id === $target->id) {
                return true;
            }
            $current = $current->manager;
        }

        return false;
    }
}
