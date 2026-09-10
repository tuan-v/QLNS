<?php

namespace App\Repositories;

use App\Models\AttendanceAdjustment;
use App\Models\Employee;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AttendanceAdjustmentRepository
{
    public function create(array $data): AttendanceAdjustment
    {
        return AttendanceAdjustment::create($data);
    }

    public function listForEmployee(Employee $employee): Collection
    {
        // Lọc trực tiếp theo employee_id trên chính adjustment — không còn
        // whereHas('attendance', ...) vì type=supplement chưa duyệt thì
        // attendance_id còn null (chưa có Attendance nào để join tới).
        return AttendanceAdjustment::where('employee_id', $employee->id)
            ->with(['workShift', 'attendance.workShift', 'approvedBy'])
            ->latest()
            ->get();
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return AttendanceAdjustment::with(['employee', 'workShift', 'attendance.workShift', 'requestedBy', 'approvedBy'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate($perPage);
    }
}
