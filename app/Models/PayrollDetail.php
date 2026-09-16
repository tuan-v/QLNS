<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollDetail extends Model
{
    protected $fillable = [
        'payroll_id',
        'employee_id',
        'employee_contract_id',
        'base_salary',
        'standard_work_days',
        'actual_work_days',
        'overtime_minutes',
        'overtime_amount',
        'total_allowance',
        'insurance_amount',
        'unpaid_leave_deduction',
        'other_deduction',
        'gross_salary',
        'taxable_income',
        'personal_income_tax',
        'net_salary',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'standard_work_days' => 'decimal:2',
        'actual_work_days' => 'decimal:2',
        'overtime_amount' => 'decimal:2',
        'total_allowance' => 'decimal:2',
        'insurance_amount' => 'decimal:2',
        'unpaid_leave_deduction' => 'decimal:2',
        'other_deduction' => 'decimal:2',
        'gross_salary' => 'decimal:2',
        'taxable_income' => 'decimal:2',
        'personal_income_tax' => 'decimal:2',
        'net_salary' => 'decimal:2',
    ];

    public function payroll()
    {
        return $this->belongsTo(Payroll::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function employeeContract()
    {
        return $this->belongsTo(EmployeeContract::class);
    }

    public function components()
    {
        return $this->hasMany(PayrollDetailComponent::class);
    }
}
