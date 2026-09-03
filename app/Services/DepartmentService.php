<?php

namespace App\Services;

use App\Models\Department;
use App\Repositories\DepartmentRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class DepartmentService
{
    public function __construct(private readonly DepartmentRepository $departmentRepository)
    {
    }

    public function list(): LengthAwarePaginator
    {
        return $this->departmentRepository->paginate();
    }

    public function create(array $data): Department
    {
        return DB::transaction(fn () => $this->departmentRepository->create($data));
    }

    public function update(Department $department, array $data): Department
    {
    if (array_key_exists('parent_id', $data)
        && $this->departmentRepository->wouldCreateCycle($department->id, $data['parent_id'])) {
        throw ValidationException::withMessages([
            'parent_id' => 'Không thể chọn phòng ban này làm cha vì sẽ tạo vòng lặp trong cơ cấu tổ chức.',
        ]);
    }

    return DB::transaction(fn () => $this->departmentRepository->update($department, $data));
    }

    public function delete(Department $department): void
    {
        $this->departmentRepository->delete($department);
    }
    public function tree(): \Illuminate\Support\Collection
    {
        return $this->departmentRepository->tree();
    }

    
}
