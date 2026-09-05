<?php

namespace App\Repositories;

use App\Models\Employee;
use App\Models\EmployeeContract;
use Illuminate\Support\Collection;

class EmployeeContractRepository
{
    public function listByEmployee(Employee $employee): Collection
    {
        return $employee->contracts()->latest('start_date')->get();
    }

    public function find(int $id): ?EmployeeContract
    {
        return EmployeeContract::query()->find($id);
    }

    public function create(array $data): EmployeeContract
    {
        return EmployeeContract::create($data);
    }
}
