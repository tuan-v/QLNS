<?php

namespace App\Repositories;

use App\Models\Employee;
use Illuminate\Pagination\LengthAwarePaginator;

class EmployeeRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return Employee::with(['department', 'position', 'manager'])
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('company_email', 'like', "%{$search}%");
                });
            })
            ->when($filters['department_id'] ?? null, fn ($query, $departmentId) => $query->where('department_id', $departmentId))
            ->when($filters['employment_status'] ?? null, fn ($query, $status) => $query->where('employment_status', $status))
            ->latest()
            ->paginate($perPage);
    }
    public function find(int $id): ?Employee
    {
        return Employee::query()->with(['department', 'position', 'manager'])->find($id);
    }

    // Đếm nhân viên theo employment_status bằng 1 query group-by duy nhất,
    // thay vì gọi count() riêng cho từng trạng thái (4 query rời rạc).
    public function countByStatus(): \Illuminate\Support\Collection
    {
        return Employee::query()
            ->selectRaw('employment_status, count(*) as total')
            ->groupBy('employment_status')
            ->pluck('total', 'employment_status');
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

        return in_array($target->id, $this->ancestorIds($viewer), true);
    }

    /**
     * Danh sách ID toàn bộ cấp trên (mọi cấp) của 1 nhân viên, đi ngược từ manager
     * lên tới người không còn quản lý. Tách riêng khỏi isSuperiorOf() để nơi gọi
     * (EmployeeResource) có thể tính đúng 1 lần cho cả danh sách, thay vì lặp lại
     * phép duyệt này cho từng dòng — cùng 1 người xem thì chuỗi cấp trên không đổi.
     */
    public function ancestorIds(Employee $employee): array
    {
        $ids = [];
        $current = $employee->manager;

        while ($current !== null) {
            $ids[] = $current->id;
            $current = $current->manager;
        }

        return $ids;
    }
}
