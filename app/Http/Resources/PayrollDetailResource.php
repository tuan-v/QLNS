<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PayrollDetailResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'code' => $this->employee->code,
                'full_name' => $this->employee->full_name,
                'position_name' => $this->employee->position?->name,
                'department_name' => $this->employee->department?->name,
            ]),
            'base_salary' => $this->base_salary,
            'standard_work_days' => $this->standard_work_days,
            'actual_work_days' => $this->actual_work_days,
            'overtime_minutes' => $this->overtime_minutes,
            'overtime_amount' => $this->overtime_amount,
            'total_allowance' => $this->total_allowance,
            'unpaid_leave_deduction' => $this->unpaid_leave_deduction,
            'other_deduction' => $this->other_deduction,
            'insurance_amount' => $this->insurance_amount,
            'gross_salary' => $this->gross_salary,
            'taxable_income' => $this->taxable_income,
            'personal_income_tax' => $this->personal_income_tax,
            'net_salary' => $this->net_salary,
            'payroll' => $this->whenLoaded('payroll', fn () => [
                'id' => $this->payroll->id,
                'period_month' => $this->payroll->period_month,
                'period_year' => $this->payroll->period_year,
                'status' => $this->payroll->status,
            ]),

        ];
    }
}
