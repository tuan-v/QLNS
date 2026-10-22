<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;

// Phạm vi dữ liệu theo phòng ban (2026-10-22, quyết định người dùng): HR/Admin xem cả công ty;
// Trưởng phòng (và mọi tài khoản xem được nhân sự nhưng không có quyền cấp công ty) chỉ xem phòng
// mình quản lý + phòng con — áp dụng cho Nhân viên, Tổng quan, Chấm công, Nghỉ phép, Phòng ban/Chức
// vụ, Báo cáo. Lương chỉ người có payroll.view_all (hoặc chính chủ) mới thấy.
class DepartmentScopeService
{
    public function canViewCompany(User $user): bool
    {
        return $user->hasPermission('payroll.view_all') || $user->hasPermission('department.manage');
    }

    /**
     * Phòng ban người dùng được xem: null = cả công ty; mảng id = phòng mình quản lý + phòng con
     * (mảng rỗng = không quản lý phòng nào, không thấy ai).
     *
     * @return array<int, int>|null
     */
    public function allowedDepartmentIds(User $user): ?array
    {
        if ($this->canViewCompany($user)) {
            return null;
        }

        $employeeId = $user->employee?->id;
        $roots = $employeeId ? Department::where('manager_id', $employeeId)->pluck('id')->all() : [];

        return $roots === [] ? [] : Department::withDescendantIds($roots);
    }

    /** Xem được hồ sơ 1 nhân viên: cấp công ty, chính mình, hoặc nhân viên thuộc phòng mình quản lý. */
    public function canViewEmployee(User $user, Employee $employee): bool
    {
        if ($user->employee?->id === $employee->id) {
            return true;
        }

        $allowed = $this->allowedDepartmentIds($user);

        return $allowed === null || in_array($employee->department_id, $allowed, true);
    }

    /** Thấy lương (lương thỏa thuận, lương đóng BH, phiếu lương): quyền xem lương toàn công ty hoặc chính chủ. */
    public function canViewSalary(?User $user, ?int $employeeId): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasPermission('payroll.view_all')
            || ($employeeId !== null && $user->employee?->id === $employeeId);
    }
}
