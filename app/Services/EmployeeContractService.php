<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Repositories\EmployeeContractRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EmployeeContractService
{
    public function __construct(private readonly EmployeeContractRepository $employeeContractRepository)
    {
    }

    public function listForEmployee(Employee $employee): Collection
    {
        return $this->employeeContractRepository->listByEmployee($employee);
    }

    public function create(Employee $employee, array $data, UploadedFile $file): EmployeeContract
    {
        $data['employee_id'] = $employee->id;
        $data['contract_file_path'] = $file->store('contracts', 'local');
        return DB::transaction(fn () => $this->employeeContractRepository->create($data));
    }
}
