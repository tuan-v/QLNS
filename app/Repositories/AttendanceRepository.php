<?php

namespace App\Repositories;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\WorkShift;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AttendanceRepository
{
    public function findForShift(Employee $employee, int $workShiftId, string $date): ?Attendance
    {
        return Attendance::where('employee_id', $employee->id)
            ->where('work_shift_id', $workShiftId)
            ->where('attendance_date', $date)
            ->first();
    }

    // 1 dòng/ca/ngày (unique employee_id+attendance_date+work_shift_id) — 1
    // nhân viên có nhiều ca cùng ngày (mục 14, vd Ca sáng + Ca chiều) thì mỗi
    // ca chấm công độc lập, ra 1 dòng attendances riêng.
    public function findOrCreateForShift(Employee $employee, WorkShift $workShift, string $date): Attendance
    {
        return Attendance::firstOrCreate(
            ['employee_id' => $employee->id, 'work_shift_id' => $workShift->id, 'attendance_date' => $date],
            [
                'scheduled_work_minutes' => $workShift->standard_work_minutes,
                'status' => 'pending',
            ],
        );
    }

    public function listForEmployee(Employee $employee, int $limit = 30): Collection
    {
        return Attendance::where('employee_id', $employee->id)
            ->with('workShift')
            ->latest('attendance_date')
            ->limit($limit)
            ->get();
    }

    // Dùng cho báo cáo "Lịch sử chấm công" (mục 18) — nạp cả logs.attendanceLocation
    // để trang xem chi tiết (phía Admin) không phải gọi thêm request riêng,
    // dữ liệu 1 tháng chỉ vài chục dòng nên không đáng lo hiệu năng.
    public function listForEmployeeInRange(Employee $employee, string $from, string $to): Collection
    {
        return Attendance::where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$from, $to])
            ->with(['workShift', 'logs.attendanceLocation'])
            ->get();
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return Attendance::with(['employee', 'workShift'])
            ->when($filters['employee_id'] ?? null, fn ($query, $id) => $query->where('employee_id', $id))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->where('attendance_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->where('attendance_date', '<=', $date))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('attendance_date')
            ->paginate($perPage);
    }
}
