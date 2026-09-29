<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;

// "Quản lý trực tiếp" (employees.manager_id) KHÔNG còn chọn tay (2026-09-29,
// theo yêu cầu người dùng) — luôn TỰ SUY RA từ cơ cấu phòng ban:
//   - Quản lý = Trưởng phòng (departments.manager_id) của phòng ban nhân viên đó.
//   - Chính người đó là Trưởng phòng, hoặc phòng ban chưa có Trưởng phòng ->
//     đi ngược lên phòng ban CHA, lấy Trưởng phòng gần nhất (không phải chính họ).
//   - Không còn cấp nào -> null (đơn nghỉ phép đi thẳng lên HR, xem
//     LeaveApprovalService).
// Vẫn LƯU vào cột manager_id (không tính động) vì duyệt phép/Dashboard/ẩn
// lương (EmployeeResource) đang đọc thẳng cột này. Mọi nơi làm đổi cơ cấu
// (thêm/sửa nhân viên, đổi Trưởng phòng/phòng ban cha, luân chuyển) PHẢI gọi
// sync tương ứng bên dưới; dữ liệu cũ đồng bộ 1 lần bằng lệnh
// `php artisan employees:sync-managers`.
class ReportingLineService
{
    public function resolveManagerId(Employee $employee): ?int
    {
        $department = $employee->department_id ? Department::find($employee->department_id) : null;
        $visited = [];

        while ($department !== null && ! in_array($department->id, $visited, true)) {
            $visited[] = $department->id;

            if ($department->manager_id !== null && $department->manager_id !== $employee->id) {
                return $department->manager_id;
            }

            $department = $department->parent_id ? Department::find($department->parent_id) : null;
        }

        return null;
    }

    public function syncEmployee(Employee $employee): void
    {
        $managerId = $this->resolveManagerId($employee);

        if ($employee->manager_id !== $managerId) {
            $employee->forceFill(['manager_id' => $managerId])->save();
        }
    }

    // Đổi Trưởng phòng/phòng ban cha của $department ảnh hưởng tới MỌI nhân
    // viên trong phòng ban đó VÀ các phòng ban con (Trưởng phòng con báo cáo
    // lên Trưởng phòng cha; phòng con chưa có Trưởng phòng cũng mượn cấp cha).
    public function syncDepartmentTree(Department $department): void
    {
        $departmentIds = [$department->id];
        $frontier = [$department->id];

        while ($frontier !== []) {
            $frontier = Department::whereIn('parent_id', $frontier)
                ->whereNotIn('id', $departmentIds)
                ->pluck('id')
                ->all();
            $departmentIds = array_merge($departmentIds, $frontier);
        }

        Employee::whereIn('department_id', $departmentIds)->get()->each(fn (Employee $e) => $this->syncEmployee($e));

        // Trưởng phòng của phòng ban này có thể thuộc (department_id) phòng
        // ban khác — vẫn đồng bộ lại cho chắc.
        if ($department->manager_id) {
            $head = Employee::find($department->manager_id);
            $head && $this->syncEmployee($head);
        }
    }

    public function syncAll(): int
    {
        $changed = 0;

        Employee::query()->each(function (Employee $employee) use (&$changed) {
            $before = $employee->manager_id;
            $this->syncEmployee($employee);
            $changed += $before !== $employee->manager_id ? 1 : 0;
        });

        return $changed;
    }
}
