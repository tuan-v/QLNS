<?php

namespace App\Repositories;

use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\EmployeeChecklist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ChecklistRepository
{
    // Quan hệ cần để hiển thị + tự đánh dấu việc hệ thống (tài khoản, hợp đồng).
    public const DETAIL_RELATIONS = [
        'items', 'items.completer:id,user_name',
        'employee:id,code,full_name,avatar,department_id,position_id,manager_id,user_id,hire_date,employment_status',
        'employee.department:id,name', 'employee.position:id,name',
        'employee.user:id,status', 'employee.contracts:id,employee_id,status,contract_file_path',
        'employee.bankAccounts:id,employee_id,status,is_primary',
    ];

    // $managerEmployeeId != null: chỉ checklist của nhân viên quản lý trực tiếp bởi người đó.
    public function paginate(array $filters, int $perPage, ?int $managerEmployeeId): LengthAwarePaginator
    {
        return EmployeeChecklist::query()
            ->with(self::DETAIL_RELATIONS)
            ->whereHas('employee', function (Builder $q) use ($filters, $managerEmployeeId) {
                if ($managerEmployeeId !== null) {
                    $q->where('manager_id', $managerEmployeeId);
                }
                if ($search = $filters['search'] ?? null) {
                    $q->where(fn (Builder $q2) => $q2->where('full_name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
                }
            })
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 ELSE 1 END")
            ->orderByDesc('reference_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function loadDetail(EmployeeChecklist $checklist): EmployeeChecklist
    {
        return $checklist->load(self::DETAIL_RELATIONS);
    }

    /** @return Collection<int, EmployeeChecklist> */
    public function forEmployee(Employee $employee): Collection
    {
        return EmployeeChecklist::query()
            ->with(self::DETAIL_RELATIONS)
            ->where('employee_id', $employee->id)
            ->where('status', '!=', EmployeeChecklist::STATUS_CANCELLED)
            ->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 ELSE 1 END")
            ->latest('id')
            ->limit(5)
            ->get();
    }

    public function openChecklist(Employee $employee, string $type): ?EmployeeChecklist
    {
        return EmployeeChecklist::where('employee_id', $employee->id)
            ->where('type', $type)
            ->where('status', EmployeeChecklist::STATUS_IN_PROGRESS)
            ->first();
    }

    public function defaultTemplate(string $type): ?ChecklistTemplate
    {
        return ChecklistTemplate::with('items')
            ->where('type', $type)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /** @return Collection<int, ChecklistTemplate> */
    public function templates(?string $type): Collection
    {
        return ChecklistTemplate::with('items')
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('type')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }
}
