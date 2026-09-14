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
            ]),
            'base_salary' => $this->base_salary,
            'standard_work_days' => $this->standard_work_days,
            'actual_work_days' => $this->actual_work_days,
            'overtime_minutes' => $this->overtime_minutes,
            'unpaid_leave_deduction' => $this->unpaid_leave_deduction,
            'insurance_amount' => $this->insurance_amount,
            'gross_salary' => $this->gross_salary,
            'taxable_income' => $this->taxable_income,
            'personal_income_tax' => $this->personal_income_tax,
            'net_salary' => $this->net_salary,
        ];
    }
}
