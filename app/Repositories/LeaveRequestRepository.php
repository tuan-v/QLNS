<?php

namespace App\Repositories;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class LeaveRequestRepository
{
    public function create(array $data): LeaveRequest
    {
        return LeaveRequest::create($data);
    }

    public function listForEmployee(Employee $employee, int $limit = 30): Collection
    {
        return LeaveRequest::where('employee_id', $employee->id)
            ->with('leaveType')
            ->latest('from_date')
            ->limit($limit)
            ->get();
    }

    // Đơn nghỉ phép nào của nhân viên này (bất kỳ loại phép nào) đang ĐÈ
    // NGÀY lên khoảng [fromDate, toDate] và chưa bị từ chối (pending/
    // manager_approved/approved đều tính, chỉ 'rejected' mới bỏ qua) — dùng
    // để chặn tạo đơn trùng ngày (mục 19, xác nhận với người dùng). So khoảng
    // ngày kiểu chuẩn: A.from <= B.to AND A.to >= B.from.
    public function findOverlapping(Employee $employee, string $fromDate, string $toDate): ?LeaveRequest
    {
        return LeaveRequest::where('employee_id', $employee->id)
            ->where('status', '!=', 'rejected')
            ->where('from_date', '<=', $toDate)
            ->where('to_date', '>=', $fromDate)
            ->first();
    }

    // Tổng total_days của các đơn CÙNG loại phép, CÙNG năm (theo from_date),
    // đang pending hoặc manager_approved (chưa có kết quả cuối) — dùng để
    // tính "số ngày khả dụng còn đăng ký được", vì used_days chỉ cộng lúc
    // duyệt xong (mục 19), không phản ánh các đơn đang chờ.
    public function sumPendingDaysForYear(int $employeeId, int $leaveTypeId, int $year): float
    {
        return (float) LeaveRequest::where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereYear('from_date', $year)
            ->whereIn('status', ['pending', 'manager_approved'])
            ->sum('total_days');
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return LeaveRequest::with(['employee', 'leaveType'])
            ->when($filters['employee_id'] ?? null, fn ($query, $id) => $query->where('employee_id', $id))
            ->when($filters['leave_type_id'] ?? null, fn ($query, $id) => $query->where('leave_type_id', $id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('from_date')
            ->paginate($perPage);
    }
}
