<?php

namespace App\Repositories;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollDetail;
use Illuminate\Support\Collection;

class PayrollDetailRepository
{
    public function createForPayroll(Payroll $payroll, array $data): PayrollDetail
    {
        return $payroll->details()->create($data);
    }

    public function listForPayroll(Payroll $payroll): Collection
    {
        return $payroll->details()->with('employee')->get();
    }

    // Lịch sử phiếu lương của 1 nhân viên qua các kỳ — chỉ trả kỳ ĐÃ CHỐT
    // (closed/paid), cùng lý do PayrollController::mine() cũ giải thích: kỳ
    // "processing" còn có thể bị HR sửa số liệu, chưa nên xem là bản chính
    // thức. Dùng chung cho cả nhân viên tự xem (mine()) lẫn HR xem 1 nhân
    // viên bất kỳ (forEmployee()).
    public function listForEmployee(Employee $employee): Collection
    {
        return PayrollDetail::where('employee_id', $employee->id)
            ->whereHas('payroll', fn ($query) => $query->whereIn('status', ['closed', 'paid']))
            ->with(['payroll', 'employee.position', 'employee.department'])
            ->latest('id')
            ->get();
    }
}
