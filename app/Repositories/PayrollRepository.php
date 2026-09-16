<?php

namespace App\Repositories;

use App\Models\Payroll;
use Illuminate\Pagination\LengthAwarePaginator;

class PayrollRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return Payroll::query()
            ->when($filters['year'] ?? null, fn ($query, $year) => $query->where('period_year', $year))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('period_year')
            ->latest('period_month')
            ->paginate($perPage);
    }

    public function find(int $id): ?Payroll
    {
        return Payroll::with(['details.employee.position', 'details.employee.department', 'createdBy', 'closedBy'])->find($id);
    }

    public function findByPeriod(int $month, int $year): ?Payroll
    {
        return Payroll::where('period_month', $month)->where('period_year', $year)->first();
    }

    public function create(array $data): Payroll
    {
        return Payroll::create($data);
    }

    public function update(Payroll $payroll, array $data): Payroll
    {
        $payroll->update($data);

        return $payroll;
    }
}
