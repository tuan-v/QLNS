<?php

namespace App\Repositories;

use App\Models\Employee;
use Illuminate\Pagination\LengthAwarePaginator;

class EmployeeRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        // 'activeContract'/'currentYearLeaveBalance' (2026-09-24) — cột
        // "Lương"/"Nghỉ phép" ở danh sách nhân viên, nạp cùng lượt tránh N+1
        // (mỗi trang tối đa perPage dòng, mỗi quan hệ chỉ 1 query riêng, không
        // lặp theo từng dòng).
        return Employee::with(['department', 'position', 'manager', 'activeContract', 'currentYearLeaveBalance'])
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
    /**
     * Danh sách ID toàn bộ cấp trên (mọi cấp) của 1 nhân viên, đi ngược từ manager
     * lên tới người không còn quản lý. Nơi gọi (EmployeeResource) tính đúng 1 lần
     * cho cả danh sách — cùng 1 người xem thì chuỗi cấp trên không đổi.
     */
    public function ancestorIds(Employee $employee): array
    {
        $ids = [];
        $current = $employee->manager;

        // Dừng khi gặp lại người đã đi qua — quản lý giờ tự suy từ cơ cấu phòng
        // ban (ReportingLineService), cấu hình Trưởng phòng chéo nhau (A làm
        // Trưởng phòng X nhưng thuộc phòng Y, B làm Trưởng phòng Y nhưng thuộc
        // phòng X) có thể tạo vòng, không được treo vòng lặp vô hạn.
        while ($current !== null && ! in_array($current->id, $ids, true) && $current->id !== $employee->id) {
            $ids[] = $current->id;
            $current = $current->manager;
        }

        return $ids;
    }
}
