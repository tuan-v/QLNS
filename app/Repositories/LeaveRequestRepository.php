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
