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
        // fresh(): client không gửi "status" nên Eloquent không biết giá trị
        // DEFAULT 'active' MySQL tự gán ở tầng DB — object trả về ngay sau
        // create() sẽ có status=null dù DB đã lưu đúng 'active', khiến
        // response trả JSON status=null (Frontend hiện "—" thay vì đúng chip
        // trạng thái) cho tới khi tải lại trang (query lại DB mới đúng). Phát
        // hiện qua kiểm thử thật (Playwright), không phải suy đoán.
        return EmployeeContract::create($data)->fresh();
    }

    public function update(EmployeeContract $contract, array $data): EmployeeContract
    {
        $contract->update($data);

        return $contract;
    }
}
