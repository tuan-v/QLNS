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
            'bonus_amount' => $this->bonus_amount,
            // Thanh toán phép chưa nghỉ khi nghỉ việc (âm = đã nghỉ quá, không trừ lương).
            'leave_payout_days' => $this->leave_payout_days,
            'leave_payout_amount' => $this->leave_payout_amount,
            // Tên dịp thưởng (vd "Thưởng Quốc khánh") — chỉ có khi đã nạp components.
            'bonus_label' => $this->whenLoaded('components', fn () => $this->components->first(fn ($c) => isset($c->calculation_snapshot['holiday_bonuses']))?->component_name_snapshot),
            'total_allowance' => $this->total_allowance,
            // Từng khoản phụ cấp / trợ cấp (bản chụp lúc tính lương) — mục II của phiếu lương.
            'allowances' => $this->whenLoaded('components', fn () => $this->components
                ->filter(fn ($c) => isset($c->calculation_snapshot['allowance']))
                ->map(fn ($c) => [
                    'name' => $c->component_name_snapshot,
                    'amount' => (float) $c->amount,
                    'monthly_amount' => $c->calculation_snapshot['allowance']['monthly_amount'] ?? null,
                    'unit' => $c->calculation_snapshot['allowance']['unit'] ?? 'month',
                    'note' => $c->calculation_snapshot['allowance']['note'] ?? null,
                    'days' => $c->calculation_snapshot['allowance']['days'] ?? null,
                    'standard_days' => $c->calculation_snapshot['allowance']['standard_days'] ?? null,
                ])
                ->values()),
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
