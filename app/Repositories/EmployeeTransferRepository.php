<?php

namespace App\Repositories;

use App\Models\Employee;
use App\Models\EmployeeTransfer;
use Illuminate\Support\Collection;

class EmployeeTransferRepository
{
    public function listByEmployee(Employee $employee): Collection
    {
        return $employee->transfers()
            ->with(['fromDepartment', 'toDepartment', 'newManager', 'oldPosition', 'newPosition', 'approver'])
            ->latest('effective_date')
            ->get();
    }

    public function create(array $data): EmployeeTransfer
    {
        return EmployeeTransfer::create($data);
    }
}
