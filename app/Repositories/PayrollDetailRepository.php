<?php

namespace App\Repositories;

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
}
